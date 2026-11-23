<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $occurred_at
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $ip
 * @property array<string, mixed> $context
 * @property string $prev_hash
 * @property string $hash
 */
final class AuditLog extends Model
{
    public $timestamps = false;

    /** Keep microseconds: the hash covers occurred_at at full precision. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'context' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
