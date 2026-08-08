<?php

declare(strict_types=1);

namespace App\Enum;

enum LockLevel: string
{
    case NONE = 'NONE';
    /**
     * ⚠ N'EXISTE QUE POUR ÊTRE REFUSÉ (ENG-21). Le solveur ne lit jamais la pénalité de
     * verrou souple : l'accepter serait un « déclaré ≠ effectif ». `ManualEditController`
     * s'appuie sur ce cas pour répondre « use NONE or HARD » au lieu d'un générique — le
     * retirer dégraderait le message. L'union TS correspondante l'omet exprès (écart déclaré
     * dans `TsUnionsMatchPhpEnumsTest`).
     */
    case SOFT = 'SOFT';
    case HARD = 'HARD';
}
