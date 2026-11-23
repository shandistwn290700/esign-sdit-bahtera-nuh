<?php

declare(strict_types=1);

namespace App\Enums;

enum SignerStepStatus: string
{
    case Waiting = 'waiting';   // an earlier signer has not signed yet
    case Pending = 'pending';   // this signer's turn
    case Signed = 'signed';
    case Rejected = 'rejected';
    case Skipped = 'skipped';   // document rejected/revoked before this turn
}
