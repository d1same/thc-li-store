<?php
declare(strict_types=1);

use App\Auth;
use App\Store;

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim((string) (getenv('APP_BASE') ?: ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $clean = ltrim($path, '/');
    $file = APP_ROOT . '/public/assets/' . $clean;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . $clean) . '?v=' . rawurlencode($version);
}

function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

function output_json(array $payload, int $flags = 0): void
{
    echo json_encode(
        $payload,
        $flags | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('This form expired. Please go back and try again.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

function setting(string $key, mixed $default = null): mixed
{
    return Store::setting($key, $default);
}

function current_user(): ?array
{
    return Auth::user();
}

function is_admin(): bool
{
    return Auth::isStaff();
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function render(string $view, array $data = []): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');
    }
    extract($data, EXTR_SKIP);
    $viewFile = APP_ROOT . '/app/Views/' . $view . '.php';
    if (!is_file($viewFile)) {
        throw new RuntimeException('View not found: ' . $view);
    }
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    require APP_ROOT . '/app/Views/layout.php';
}
