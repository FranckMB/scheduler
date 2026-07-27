<?php

declare(strict_types=1);

namespace App\Message\Basketball;

use UnexpectedValueException;

/**
 * Dispatched after a new club is created (AuthController::verifyEmail) to fill
 * its institutional data from the FFBB API asynchronously (lot C) — the register
 * flow never blocks on the external call, and a failure is best-effort.
 */
final readonly class PopulateClubFromFfbbMessage
{
    public function __construct(
        private string $clubId,
    ) {}

    /**
     * Décode AUSSI les enveloppes sérialisées avant le renommage (P4-17).
     *
     * L'alias de classe (`src/compat_class_aliases.php`) ne suffit PAS : PHP
     * sérialise une propriété PRIVÉE sous une clé « mangled » qui contient le nom
     * de la classe (`\0App\Message\PopulateClubFromFfbbMessage\0clubId`). Après
     * renommage, l'alias fait bien résoudre la classe, mais la clé ne correspond
     * plus à la propriété — PHP tente alors de créer une propriété dynamique et
     * échoue. Les deux mécanismes sont donc nécessaires : l'alias pour trouver la
     * classe, celui-ci pour lire la charge utile.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $legacyKey = "\0App\Message\PopulateClubFromFfbbMessage\0clubId";
        $currentKey = "\0" . self::class . "\0clubId";
        $clubId = $data[$currentKey] ?? $data[$legacyKey] ?? null;

        if (!\is_string($clubId)) {
            throw new UnexpectedValueException('PopulateClubFromFfbbMessage: clubId absent de la charge sérialisée.');
        }

        $this->clubId = $clubId;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }
}
