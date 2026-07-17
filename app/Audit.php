<?php
declare(strict_types=1);

namespace App;

final class Audit
{
    public static function record(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $details = [],
        ?int $userId = null
    ): void {
        Database::execute(
            'INSERT INTO audit_events (user_id,action,entity_type,entity_id,details,ip_address) VALUES (?,?,?,?,?,?)',
            [
                $userId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null),
                $action,
                $entityType,
                $entityId,
                json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                RateLimiter::ip(),
            ]
        );
    }
}
