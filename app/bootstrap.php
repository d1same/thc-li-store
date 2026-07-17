<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($key) === false) {
            putenv($key . '=' . trim($value, "\"'"));
        }
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

$production = (string) (getenv('APP_ENV') ?: 'production') === 'production';
if ($production) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('zend.exception_ignore_args', '1');
    if (PHP_SAPI !== 'cli') {
        set_exception_handler(static function (Throwable $error): void {
            error_log(sprintf('[THC-LI] %s in %s:%d', $error->getMessage(), $error->getFile(), $error->getLine()));
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: no-store');
            }
            exit('The application encountered an unexpected error. Please try again later.');
        });
    }
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_name('localshop_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

date_default_timezone_set('America/New_York');

\App\Database::boot();
\App\Migration::run();
\App\Auth::enforceSessionPolicy();
$configuredTimezone = (string) \App\Store::setting('report_timezone', 'America/New_York');
try {
    date_default_timezone_set((new DateTimeZone($configuredTimezone))->getName());
} catch (Throwable) {
    date_default_timezone_set('America/New_York');
}
