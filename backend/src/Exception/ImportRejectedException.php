<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Un import refusé pour une raison MÉTIER, avec un message écrit pour être lu
 * par le gestionnaire (P4-5).
 *
 * Pourquoi un type dédié plutôt que les `InvalidArgumentException` /
 * `RuntimeException` nues d'avant : les contrôleurs d'import relayaient
 * `$e->getMessage()` à l'utilisateur en se fiant à la CLASSE de l'exception.
 * Or `PhpOffice\PhpSpreadsheet\Reader\Exception` **étend `RuntimeException`** —
 * une exception de librairie tombait donc dans le même `catch` et sa chaîne
 * partait en 422. Reproduit :
 *
 *     File "/var/www/html/var/tmp/php7Zx9Qa" does not exist or is not readable.
 *
 * Le chemin serveur atterrissait dans le toast du gestionnaire. Le déclencheur
 * est étroit (fichier temporaire disparu), mais le vrai défaut est le principe :
 * relayer la chaîne d'une dépendance n'a aucune borne — rien ne garantit que le
 * prochain message d'une future version soit inoffensif.
 *
 * Désormais seul CE type est relayé. Tout le reste est journalisé et remplacé
 * par un message générique.
 */
final class ImportRejectedException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        private readonly int $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    /** Fichier malformé / colonnes manquantes : la requête elle-même est mauvaise. */
    public static function badRequest(string $userMessage): self
    {
        return new self($userMessage, Response::HTTP_BAD_REQUEST);
    }

    /** Le fichier est lisible mais une règle métier le refuse. */
    public static function unprocessable(string $userMessage): self
    {
        return new self($userMessage);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
