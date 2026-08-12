<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\PayloadCapacityMirror;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P3-19 — LE GARDE CROSS-STACK du miroir d'algèbre (décision fondateur 2026-08-07 :
 * épingler la parité plutôt que déplacer le calcul côté moteur).
 *
 * `PayloadCapacityMirror` (PHP) réplique une partie de l'algèbre de créneaux du
 * moteur pour que le récap annonce des chiffres justes. Deux copies de la même
 * logique = un jour, quelqu'un change l'une sans l'autre et LE RÉCAP MENT EN
 * SILENCE (sept divergences trouvées en deux rounds de revue #341 — le risque
 * n'est pas théorique). Ce test envoie les MÊMES payloads au VRAI moteur et
 * vérifie que les deux copies rendent le même verdict.
 *
 * Chaque scénario est construit pour que les règles répliquées SOIENT décisives :
 * un payload où elles ne jouent pas passerait au vert quelle que soit l'algèbre.
 *
 * Même régime que ContractSchemaTest : moteur réel sur le réseau docker, skip
 * propre s'il est indisponible (le groupe `contract` tourne là où il existe).
 */
#[Group('contract')]
final class CapacityMirrorParityTest extends TestCase
{
    private const string ENGINE_URL = 'http://engine:8000/generate';

    private PayloadCapacityMirror $mirror;

    /**
     * LA règle du miroir — dédup par triplet. (Le rabot `FACILITY_CAPACITY` en était
     * une seconde ; la famille a été retirée le 2026-08-08, aucun chemin UI ne la
     * créait.).
     *
     * Grille : le même triplet (V, lundi, 18:00) déclaré DEUX fois à capacité 3
     * (le moteur écrase, il n'additionne pas) + un second créneau (mercredi,
     * capacité 3). Miroir attendu : 3 + 3 = 6 places — PAS 3+3+3=9 (sans dédup).
     *
     * Face moteur : 6 équipes à 1 séance → il doit en placer EXACTEMENT `offer`.
     * S'il place plus, le miroir sous-estime (fausses alertes de sous-capacité) ;
     * s'il place moins, le miroir sur-estime (le récap promet des places qui
     * n'existent pas). Les deux sens rougissent.
     */
    public function testOfferMatchesWhatTheEngineActuallyPlaces(): void
    {
        $payload = $this->basePayload(
            teams: $this->teams(6),
            venues: [[
                'id' => 'v1', 'name' => 'V1', 'isActive' => true,
                'trainingSlots' => [
                    ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 3],
                    ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 3], // doublon exact
                    ['dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 3],
                ],
            ]],
        );

        $offer = $this->mirror->offer($payload);
        self::assertSame(6, $offer, 'le miroir PHP doit dédupliquer le triplet (2 lignes au même créneau = 1 place-set)');

        $result = $this->solve($payload);
        self::assertSame('completed', $result['status']);
        self::assertCount(
            $offer,
            $result['slots'],
            'PARITÉ ROMPUE : le moteur ne place pas le nombre de séances que le miroir annonce — '
            . 'son algèbre de créneaux a changé (dédup par triplet). '
            . 'Aligner App\Service\PayloadCapacityMirror, sinon le récap ment.',
        );
    }

    /**
     * Règle « saturation » — un pin HARD ferme son triplet à TOUT le monde et ne
     * compte PAS pour les « au moins » de sa propre équipe.
     *
     * 2 équipes exigent chacune 1 séance à V1 (2 places demandées) ; V1 a 2 créneaux
     * mais l'un est épinglé par une TROISIÈME équipe → 1 place libre. Le miroir dit
     * saturé (2 > 1) ; le moteur doit être INFEASIBLE. Témoin : sans le pin, les
     * deux verdicts basculent ensemble (rien à signaler / completed).
     */
    public function testSaturationVerdictMatchesEngineFeasibility(): void
    {
        $venues = [[
            'id' => 'v1', 'name' => 'V1', 'isActive' => true,
            'trainingSlots' => [
                ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
                ['dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
            ],
        ]];
        $minConstraints = [
            $this->minAtVenue('t1', 'v1'),
            $this->minAtVenue('t2', 'v1'),
        ];
        $pin = [[
            'id' => 'pin-1', 'teamId' => 't3', 'venueId' => 'v1', 'coachId' => null,
            'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90,
            'lockLevel' => 'HARD', 'temporaryLock' => false, 'temporaryLockFor' => null,
            'temporaryMinSessionsOverride' => null, 'pendingConstraintSuggestion' => null,
        ]];

        $saturatedPayload = $this->basePayload(teams: $this->teams(3), venues: $venues, constraints: $minConstraints, slotTemplates: $pin);
        $saturation = $this->mirror->venueMinimumSaturation($saturatedPayload);
        self::assertCount(1, $saturation, 'le miroir doit voir la saturation (2 minimums pour 1 place libre)');
        self::assertSame(['demand' => 2, 'free' => 1], ['demand' => $saturation[0]['demand'], 'free' => $saturation[0]['free']]);
        self::assertSame(
            'failed',
            $this->solve($saturatedPayload)['status'],
            'PARITÉ ROMPUE : le miroir annonce un INFEASIBLE certain mais le moteur a résolu — '
            . 'son algèbre des minimums ou des verrous a changé (pins comptés ? triplet non fermé ?). '
            . 'Aligner App\Service\PayloadCapacityMirror, sinon le récap bloque à tort.',
        );

        // TÉMOIN — sans le pin, les DEUX côtés doivent basculer ensemble : sans lui,
        // « failed » ci-dessus pourrait venir d'un tout autre facteur du payload.
        $freePayload = $this->basePayload(teams: $this->teams(3), venues: $venues, constraints: $minConstraints);
        self::assertSame([], $this->mirror->venueMinimumSaturation($freePayload));
        self::assertSame('completed', $this->solve($freePayload)['status']);
    }

    /**
     * Le COMPORTEMENT qui justifie le bloqueur « réservation orpheline » (P4-44) :
     * un pin HARD hors grille n'est PAS refusé par le moteur — il est ÉMIS tel quel,
     * statut `completed`. C'est parce que le moteur se tait que le backend doit
     * bloquer AVANT. Si ce test rougit un jour (le moteur se met à refuser), le
     * bloqueur backend est à réviser — il ferait double emploi.
     */
    public function testEngineSilentlyPlacesAnOffGridPinWhichIsWhyTheBackendBlocks(): void
    {
        $payload = $this->basePayload(
            teams: $this->teams(1),
            venues: [[
                'id' => 'v1', 'name' => 'V1', 'isActive' => true,
                'trainingSlots' => [['dayOfWeek' => 2, 'startTime' => '18:30', 'durationMinutes' => 90, 'capacity' => 1]],
            ]],
            slotTemplates: [[
                'id' => 'pin-mort', 'teamId' => 't1', 'venueId' => 'v1', 'coachId' => null,
                'dayOfWeek' => 2, 'startTime' => '18:00', 'durationMinutes' => 90, // horaire MORT
                'lockLevel' => 'HARD', 'temporaryLock' => false, 'temporaryLockFor' => null,
                'temporaryMinSessionsOverride' => null, 'pendingConstraintSuggestion' => null,
            ]],
        );

        $result = $this->solve($payload);

        self::assertSame('completed', $result['status'], 'le moteur ne refuse PAS un pin hors grille — mesuré, et épinglé ici');
        $emitted = array_column($result['slots'], 'startTime');
        self::assertContains('18:00:00', $emitted, 'la séance sort à l\'horaire MORT : la porte fermée que le bloqueur backend (P4-44) existe pour empêcher');
    }

    protected function setUp(): void
    {
        $this->mirror = new PayloadCapacityMirror;
    }

    /** @return array<string, mixed> statut + slots du VRAI moteur (skip si indisponible) */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());

            return $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function teams(int $count): array
    {
        return array_map(static fn (int $i): array => [
            'id' => 't' . $i,
            'name' => 'T' . $i,
            'sportCategoryId' => 'cat-1',
            'priorityTierId' => 3,
            'sessionsPerWeek' => 1,
            'isActive' => true,
        ], range(1, $count));
    }

    /** @return array<string, mixed> */
    private function minAtVenue(string $teamId, string $venueId): array
    {
        return [
            'id' => 'min-' . $teamId, 'scope' => 'TEAM', 'scopeTargetId' => $teamId,
            'family' => 'FACILITY', 'ruleType' => 'HARD', 'name' => 'au moins 1 à ' . $venueId,
            'config' => ['minAtVenueId' => $venueId, 'minAtVenueCount' => 1], 'sortOrder' => 0, 'isActive' => true,
        ];
    }

    /**
     * Le payload MINIMAL au contrat backend⇄engine — les clés que le moteur lit
     * vraiment (version DÉRIVÉE de la source, pas d'un littéral qui dériverait).
     * La FORME complète est gardée par ContractSchemaTest ; ici le sujet est
     * l'ALGÈBRE, sur des payloads où elle est décisive.
     *
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $constraints
     * @param list<array<string, mixed>> $slotTemplates
     *
     * @return array<string, mixed>
     */
    private function basePayload(array $teams, array $venues, array $constraints = [], array $slotTemplates = []): array
    {
        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-parity',
            'seasonId' => 'season-parity',
            'solverSeed' => 42,
            'teams' => $teams,
            'venues' => $venues,
            'coaches' => [],
            'constraints' => $constraints,
            'slotTemplates' => $slotTemplates,
        ];
    }
}
