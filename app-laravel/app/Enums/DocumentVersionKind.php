<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentVersionKind: string
{
    case Original = 'original';
    case Prepared = 'prepared';
    case Signed = 'signed';
}
