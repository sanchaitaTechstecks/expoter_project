<?php

namespace App\Services\B2B;

use App\Models\B2B\B2bAuditLog;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class B2bAuditService
{
    private static array $sensitiveKeys = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'secret',
        'bank_account_number',
        'card_number',
        'cvv',
    ];

    /**
     * Log a platform administrative or critical event (SRS Section 19).
     */
    public static function log(
        ?int $actorId,
        string $actorRole,
        string $action,
        string $entityType,
        string|int $entityId,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?string $reason = null,
        string $status = 'success'
    ): B2bAuditLog {
        $scrubbedBefore = $beforeState ? self::scrubSensitiveData($beforeState) : null;
        $scrubbedAfter = $afterState ? self::scrubSensitiveData($afterState) : null;

        $diffSummary = self::computeDiffSummary($scrubbedBefore, $scrubbedAfter);

        return B2bAuditLog::create([
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'before_state' => $scrubbedBefore,
            'after_state' => $scrubbedAfter,
            'diff_summary' => $diffSummary,
            'reason' => $reason,
            'ip_address' => Request::ip() ?? '127.0.0.1',
            'user_agent' => substr(Request::userAgent() ?? 'System / CLI', 0, 255),
            'request_id' => Request::header('X-Request-Id') ?? (string) Str::uuid(),
            'status' => $status,
        ]);
    }

    private static function scrubSensitiveData(array $data): array
    {
        $scrubbed = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), self::$sensitiveKeys, true)) {
                $scrubbed[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $scrubbed[$key] = self::scrubSensitiveData($value);
            } else {
                $scrubbed[$key] = $value;
            }
        }
        return $scrubbed;
    }

    private static function computeDiffSummary(?array $before, ?array $after): ?string
    {
        if (!$before && !$after) {
            return null;
        }

        $changes = [];
        if ($before && $after) {
            foreach ($after as $key => $newVal) {
                $oldVal = $before[$key] ?? null;
                if ($oldVal !== $newVal) {
                    $oldStr = is_scalar($oldVal) ? (string) $oldVal : json_encode($oldVal);
                    $newStr = is_scalar($newVal) ? (string) $newVal : json_encode($newVal);
                    $changes[] = "{$key}: [{$oldStr}] -> [{$newStr}]";
                }
            }
        } elseif ($after) {
            $changes[] = 'Created entity with initial attributes';
        } else {
            $changes[] = 'Deleted entity';
        }

        return implode('; ', $changes);
    }
}
