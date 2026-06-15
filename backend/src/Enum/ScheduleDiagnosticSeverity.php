<?php

declare(strict_types=1);

namespace App\Enum;

enum ScheduleDiagnosticSeverity: string
{
    case ERROR = 'ERROR';
    case WARNING = 'WARNING';
    case INFO = 'INFO';
    case SUCCESS = 'SUCCESS';
}
