<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Dto\VenueTrainingSlotInput;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * D-36 — un geste OFFERT par l'écran doit être ACCEPTÉ par l'API.
 *
 * Le 2026-08-05, l'écran Gymnases est passé à trois équipes par créneau (certains terrains se
 * divisent en trois en travers : cas ADN). Toute la chaîne AVAL était déjà générique sur
 * `capacity` — `ScheduleConstraintBuilder:610` lit `getCapacity()`, l'engine borne à `ge=1` sans
 * plafond, le picker de réservation suit `capacity` — et c'est précisément ce qui a masqué le
 * trou : la vérification a porté sur l'aval, jamais sur la PORTE D'ENTRÉE, restée à `max: 2`.
 * Pendant trois jours, choisir « 3 équipes » a rendu 422, sans que rien ne rougisse.
 *
 * Ce test ne garde donc PAS la valeur 3 : il garde la **parité** entre les options du sélecteur
 * et la borne du DTO, dans les deux sens. Au prochain élargissement (4 ?), l'oubli symétrique
 * rougit ici — que l'écran parte devant ou que l'API parte devant.
 *
 * Il lit le fichier TSX plutôt qu'une liste recopiée : recopier les options ici ferait de ce
 * test une TROISIÈME source de vérité, exactement le motif qu'il combat
 * (cf. `specs/evolution/duplications-de-verite.md` §1).
 */
#[Group('phase1')]
final class SlotCapacityOfferMatchesApiTest extends KernelTestCase
{
    private const string OFFER = __DIR__ . '/../../../frontend/src/features/wizard/steps/slotFields.tsx';

    public function testEveryCapacityOfferedByTheScreenIsAcceptedByTheApi(): void
    {
        $rejected = [];
        foreach ($this->capacitiesOfferedByTheScreen() as $capacity) {
            if ([] !== $this->violationsForCapacity($capacity)) {
                $rejected[] = $capacity;
            }
        }

        self::assertSame([], $rejected, \sprintf(
            "L'écran Gymnases offre ces capacités que l'API refuse : %s.\n"
            . "Un geste offert doit aboutir — sinon le gestionnaire choisit une option qui rend 422.\n"
            . 'Élargir `Assert\Range` sur VenueTrainingSlotInput::$capacity, ou retirer l\'option du sélecteur.',
            implode(', ', $rejected),
        ));
    }

    /**
     * Le sens inverse : une capacité acceptée par l'API mais jamais offerte est une
     * fonctionnalité que personne ne peut atteindre — le motif SEC-13 (`FACILITY_CAPACITY`
     * honorée par le moteur, créable par personne) appliqué à un champ.
     */
    public function testTheApiUpperBoundIsReachableFromTheScreen(): void
    {
        $offered = $this->capacitiesOfferedByTheScreen();
        $highestOffered = max($offered);

        self::assertSame([], $this->violationsForCapacity($highestOffered), 'La plus haute option du sélecteur doit être valide.');
        self::assertNotSame(
            [],
            $this->violationsForCapacity($highestOffered + 1),
            \sprintf(
                "L'API accepte une capacité de %d que l'écran n'offre pas : personne ne peut l'atteindre.\n"
                . 'Soit l\'option manque au sélecteur, soit la borne de l\'API est trop large.',
                $highestOffered + 1,
            ),
        );
    }

    /**
     * Les `<option value={N}>` du sélecteur de capacité.
     *
     * @return non-empty-list<int>
     */
    private function capacitiesOfferedByTheScreen(): array
    {
        $tsx = file_get_contents(self::OFFER);
        self::assertIsString($tsx, \sprintf('Illisible : %s', self::OFFER));

        preg_match_all('/<option value=\{(\d+)\}>/', $tsx, $found);
        $capacities = array_map(intval(...), $found[1]);

        self::assertNotEmpty(
            $capacities,
            'Aucune option de capacité trouvée — le sélecteur a-t-il changé de forme ? Ce test doit suivre, pas être neutralisé.',
        );

        return $capacities;
    }

    /** @return list<string> les champs en violation */
    private function violationsForCapacity(int $capacity): array
    {
        $input = new VenueTrainingSlotInput;
        $input->capacity = $capacity;

        // Pas de groupe : `Assert\Range` sur `$capacity` vit dans le groupe Default. Restreindre
        // à ['create'] ne l'évaluerait pas — et le test passerait au vert en ne validant rien.
        $violations = [];
        foreach ($this->validator()->validate($input) as $violation) {
            if ('capacity' === $violation->getPropertyPath()) {
                $violations[] = $violation->getPropertyPath();
            }
        }

        return $violations;
    }

    private function validator(): ValidatorInterface
    {
        self::bootKernel();

        return self::getContainer()->get(ValidatorInterface::class);
    }
}
