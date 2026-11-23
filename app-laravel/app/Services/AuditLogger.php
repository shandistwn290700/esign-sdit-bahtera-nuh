<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, hash-chained audit log (ADR-0004).
 *
 * hash = SHA-256(prev_hash || canonical JSON of the entry). Appends are serialised with a
 * PostgreSQL advisory lock so two requests can never build on the same prev_hash.
 */
final class AuditLogger
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const LOCK_KEY = 7_265_734_712; // arbitrary, unique to this table

    /** @param  array<string, mixed>  $context  never put TOTP codes, secrets or document content here */
    public function record(
        string $action,
        ?Model $subject = null,
        array $context = [],
        ?User $actor = null,
        ?string $ip = null,
    ): AuditLog {
        return DB::transaction(function () use ($action, $subject, $context, $actor, $ip): AuditLog {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            }

            $prev = AuditLog::query()->orderByDesc('id')->value('hash') ?? self::GENESIS;

            $entry = new AuditLog;
            $entry->forceFill([
                'occurred_at' => CarbonImmutable::now('UTC'),
                'actor_id' => $actor?->id,
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'ip' => $ip,
                'context' => $context,
                'prev_hash' => $prev,
            ]);
            $entry->hash = self::hashFor($entry);
            $entry->save();

            return $entry;
        });
    }

    public static function hashFor(AuditLog $entry): string
    {
        return hash('sha256', $entry->prev_hash.self::canonical([
            'occurred_at' => $entry->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'actor_id' => $entry->actor_id,
            'action' => $entry->action,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'ip' => $entry->ip,
            'context' => $entry->context,
        ]));
    }

    /** JSON with recursively sorted keys, so the hash does not depend on key order. */
    public static function canonical(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }

    /**
     * Walk the whole chain. Returns null when intact, otherwise the id of the first bad entry.
     */
    public function firstBrokenEntry(): ?int
    {
        $expectedPrev = self::GENESIS;
        $broken = null;

        AuditLog::query()->orderBy('id')->chunkById(500, function ($entries) use (&$expectedPrev, &$broken): bool {
            foreach ($entries as $entry) {
                /** @var AuditLog $entry */
                if ($entry->prev_hash !== $expectedPrev || ! hash_equals($entry->hash, self::hashFor($entry))) {
                    $broken = $entry->id;

                    return false;
                }
                $expectedPrev = $entry->hash;
            }

            return true;
        });

        return $broken;
    }
}
