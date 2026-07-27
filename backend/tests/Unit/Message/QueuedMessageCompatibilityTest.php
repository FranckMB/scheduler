<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\Basketball\PopulateClubFromFfbbMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * NR — une enveloppe SÉRIALISÉE AVANT le renommage (P4-17) doit rester décodable.
 *
 * Le transport `async` utilise le `PhpSerializer` : l'enveloppe porte le FQCN du
 * moment du dispatch. Renommer la classe rend indécodables toutes celles en
 * attente au démarrage de la nouvelle image — `MessageDecodingFailedException`,
 * enveloppe acquittée avant d'atteindre le transport `failed`, worker qui sort.
 *
 * L'impact n'est pas théorique : un club qui vérifie son email quelques secondes
 * avant le déploiement serait créé SANS aucune donnée FFBB (adresse, logo,
 * ligue/comité), sans erreur visible et sans reprise automatique.
 *
 * Ce test rejoue exactement ce scénario avec une charge utile figée portant
 * l'ANCIEN nom : il échoue si l'alias de compatibilité disparaît.
 */
#[Group('phase1')]
final class QueuedMessageCompatibilityTest extends TestCase
{
    public function testAnEnvelopeQueuedBeforeTheRenameStillDecodes(): void
    {
        // On fabrique la charge EXACTEMENT comme Redis la contient aujourd'hui :
        // une enveloppe sérialisée quand la classe s'appelait encore
        // `App\Message\PopulateClubFromFfbbMessage` — nom de classe ET clé
        // « mangled » de la propriété privée portant l'ancien FQCN.
        $current = PopulateClubFromFfbbMessage::class;
        $legacy = 'App\Message\PopulateClubFromFfbbMessage';
        $body = serialize(new Envelope(new PopulateClubFromFfbbMessage('11111111-1111-4111-8111-111111111111')));
        $legacyBody = $this->renameSerializedClass($body, $current, $legacy);

        self::assertStringContainsString($legacy, $legacyBody, 'la charge de test doit bien porter l’ancien nom');

        // PhpSerializer::encode fait `addslashes(serialize($envelope))`.
        $decoded = (new PhpSerializer)->decode(['body' => addslashes($legacyBody)]);

        $message = $decoded->getMessage();
        self::assertInstanceOf(PopulateClubFromFfbbMessage::class, $message);
        self::assertSame('11111111-1111-4111-8111-111111111111', $message->getClubId());
    }

    /**
     * Le routage de PRODUCTION, pas celui des tests.
     *
     * `RegisterDispatchesFfbbPopulationTest` assure le dispatch, mais contre le
     * transport `ffbb_in_memory` de `config/packages/test/messenger.yaml` : il
     * prouve la clé de routage de TEST. Une clé fausse dans le fichier de PROD
     * (renommage oublié) passerait donc au vert tout en dé-routant l'autofill
     * FFBB en silence — plus de transport = plus d'exécution async, sans erreur.
     */
    public function testTheProductionRoutingKeyMatchesTheCurrentFqcn(): void
    {
        $routing = file_get_contents(\dirname(__DIR__, 3) . '/config/packages/messenger.yaml');
        self::assertIsString($routing);

        self::assertStringContainsString(
            PopulateClubFromFfbbMessage::class . ': async',
            $routing,
            'messenger.yaml doit router le FQCN COURANT vers `async` : une clé périmée dé-route l’autofill FFBB sans erreur.',
        );
    }

    public function testTheLegacyFqcnStillResolvesToTheRenamedClass(): void
    {
        // La condition qui rend le décodage possible : l'alias doit être chargé
        // AVANT tout unserialize (autoload.files), pas à la demande.
        self::assertTrue(class_exists('App\Message\PopulateClubFromFfbbMessage'));
        self::assertSame(
            PopulateClubFromFfbbMessage::class,
            new ReflectionClass('App\Message\PopulateClubFromFfbbMessage')->getName(),
        );
    }

    /**
     * Réécrit un nom de classe dans une charge sérialisée, en recalculant les
     * longueurs — y compris la clé « mangled » d'une propriété privée
     * (`\0FQCN\0prop`), qui est le piège : un simple alias de classe ne la
     * couvre pas, d'où le `__unserialize` du message.
     */
    private function renameSerializedClass(string $payload, string $from, string $to): string
    {
        $rewrite = static fn (string $needle, string $replacement): array => [
            \sprintf('O:%d:"%s"', \strlen($needle), $needle) => \sprintf('O:%d:"%s"', \strlen($replacement), $replacement),
            \sprintf('s:%d:"%s"', \strlen("\0{$needle}\0clubId"), "\0{$needle}\0clubId") => \sprintf('s:%d:"%s"', \strlen("\0{$replacement}\0clubId"), "\0{$replacement}\0clubId"),
        ];

        return strtr($payload, $rewrite($from, $to));
    }
}
