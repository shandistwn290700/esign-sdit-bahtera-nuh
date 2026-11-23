<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'Dalam proses',
            self::Completed => 'Selesai',
            self::Rejected => 'Ditolak',
            self::Revoked => 'Dicabut',
        };
    }
}
