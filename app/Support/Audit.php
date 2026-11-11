<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function log(string $action, ?Model $subject, string $description, array $properties = []): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /** Untuk aksi dari job antrean: user diambil dari parameter, bukan sesi. */
    public static function system(string $action, ?Model $subject, string $description, ?int $userId = null, array $properties = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
        ]);
    }
}
