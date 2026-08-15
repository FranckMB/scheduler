<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * P4-101 — la forme d'UNE cause mesurée d'un diagnostic `session_below_effective_min`.
 *
 * Pourquoi une classe et pas un `array<string, mixed>` : la propriété était déclarée
 * en tableau nu (P4-99 PR-2), donc API Platform ne pouvait décrire qu'une forme lâche
 * — le snapshot annonçait `additionalProperties: string|null`, ce qui MENT deux fois :
 * `count` est un entier, et les noms de champs disparaissaient. Qui aurait généré ses
 * types depuis ce snapshot aurait obtenu `Record<string, string|null>`. Le front de
 * P4-99 PR-3 a dû se typer d'après le contrat engine plutôt que d'après le snapshot ;
 * cette classe supprime ce détour et rend le snapshot juste TOUT SEUL.
 *
 * ⚠ Elle DOIT rester le miroir exact de `DiagnosticCauseSchema`
 * (`engine/app/schemas/output_schema.py`) : c'est l'engine qui produit ces valeurs,
 * le backend ne fait que normaliser `constraintId` (retrait du suffixe qu'il avait
 * lui-même posé — `ScheduleDiagnosticsRecorder::normalizeCauses`).
 *
 * `kind` reste une `string` et NON un enum : l'engine en est la source de vérité
 * (`Literal` fermé côté Pydantic) et un kind ajouté là-bas ne doit pas faire tomber
 * la lecture ici — il s'affiche dégradé, il ne casse pas.
 */
final class DiagnosticCause
{
    public function __construct(
        /** Famille de la fermeture — vocabulaire de l'engine (`hard_lock`, `time_window`, …). */
        #[Groups(['read'])]
        public string $kind,
        /** UUID de la contrainte en cause, NORMALISÉ (sans suffixe) ; null si non identifiable. */
        #[Groups(['read'])]
        public ?string $constraintId,
        /** Nom lisible de la contrainte, tel que saisi par le gestionnaire ; null si absent. */
        #[Groups(['read'])]
        public ?string $label,
        /** Nombre de créneaux candidats que cette cause a fermés. */
        #[Groups(['read'])]
        public int $count,
    ) {}

    /**
     * Depuis la ligne JSON persistée (colonne `causes`), tolérante par construction :
     * la base peut porter des lignes écrites par une version antérieure du contrat.
     * Une clé absente dégrade (null / 0), elle ne lève jamais.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $kind = $row['kind'] ?? null;
        $constraintId = $row['constraintId'] ?? null;
        $label = $row['label'] ?? null;
        $count = $row['count'] ?? null;

        return new self(
            \is_string($kind) ? $kind : '',
            \is_string($constraintId) ? $constraintId : null,
            \is_string($label) ? $label : null,
            \is_int($count) ? $count : 0,
        );
    }
}
