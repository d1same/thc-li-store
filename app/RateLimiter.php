<?php
declare(strict_types=1);

namespace App;

use PDO;

final class RateLimiter
{
    public static function ip(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }

    public static function check(string $scope, string $identifier, int $limit, int $windowSeconds): array
    {
        $now = time();
        $row = Database::one(
            'SELECT * FROM rate_limits WHERE scope=? AND key_hash=?',
            [$scope, self::key($scope, $identifier)]
        );
        if (!$row || (int) $row['window_started_at'] <= $now - $windowSeconds) {
            return self::result(true, 0, $limit, 0);
        }
        $blockedUntil = (int) ($row['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            return self::result(false, (int) $row['hits'], $limit, $blockedUntil - $now);
        }
        $hits = (int) $row['hits'];
        $retry = max(1, ((int) $row['window_started_at'] + $windowSeconds) - $now);
        return self::result($hits < $limit, $hits, $limit, $hits >= $limit ? $retry : 0);
    }

    public static function hit(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds,
        ?int $blockSeconds = null
    ): array {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $blockSeconds ??= $windowSeconds;
        $now = time();
        $key = self::key($scope, $identifier);
        $pdo = Database::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT * FROM rate_limits WHERE scope=? AND key_hash=?');
            $stmt->execute([$scope, $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($row && (int) ($row['blocked_until'] ?? 0) > $now) {
                $pdo->exec('COMMIT');
                return self::result(false, (int) $row['hits'], $limit, (int) $row['blocked_until'] - $now);
            }

            $windowStart = $row ? (int) $row['window_started_at'] : $now;
            $hits = $row ? (int) $row['hits'] : 0;
            if (!$row || $windowStart <= $now - $windowSeconds) {
                $windowStart = $now;
                $hits = 0;
            }
            $hits++;
            $blockedUntil = $hits >= $limit ? $now + max(1, $blockSeconds) : null;
            $stmt = $pdo->prepare(
                'INSERT INTO rate_limits (scope,key_hash,hits,window_started_at,blocked_until,updated_at) VALUES (?,?,?,?,?,?)
                 ON CONFLICT(scope,key_hash) DO UPDATE SET hits=excluded.hits,window_started_at=excluded.window_started_at,blocked_until=excluded.blocked_until,updated_at=excluded.updated_at'
            );
            $stmt->execute([$scope, $key, $hits, $windowStart, $blockedUntil, $now]);
            if (random_int(1, 100) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE updated_at<?')->execute([$now - 172800]);
            }
            $pdo->exec('COMMIT');
            return self::result(
                $hits < $limit,
                $hits,
                $limit,
                $blockedUntil ? $blockedUntil - $now : 0
            );
        } catch (\Throwable $error) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $error;
        }
    }

    public static function clear(string $scope, string $identifier): void
    {
        Database::execute('DELETE FROM rate_limits WHERE scope=? AND key_hash=?', [$scope, self::key($scope, $identifier)]);
    }

    public static function enforce(array $result, string $message): void
    {
        if ($result['allowed']) {
            return;
        }
        $retry = max(1, (int) $result['retry_after']);
        http_response_code(429);
        header('Retry-After: ' . $retry);
        header('X-RateLimit-Limit: ' . (int) $result['limit']);
        header('X-RateLimit-Remaining: 0');
        flash('error', $message);
    }

    private static function key(string $scope, string $identifier): string
    {
        $key = (string) getenv('APP_KEY');
        if (strlen($key) < 32) {
            $key = hash('sha256', APP_ROOT . '|rate-limit-fallback');
        }
        return hash_hmac('sha256', strtolower(trim($identifier)), $key . '|' . $scope);
    }

    private static function result(bool $allowed, int $current, int $limit, int $retryAfter): array
    {
        return [
            'allowed' => $allowed,
            'current' => $current,
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'retry_after' => max(0, $retryAfter),
        ];
    }
}
