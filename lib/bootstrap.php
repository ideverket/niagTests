<?php
declare(strict_types=1);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Stockholm');

function config(): array {
    static $config;
    if ($config === null) {
        // Production always uses the sibling data directory. Environment overrides
        // are only for isolated developer/test runs, never installation files.
        $dir = getenv('NIAG_DATA_DIR') ?: dirname(__DIR__, 2).'/data';
        $settings = is_file($dir.'/settings.json') ? json_decode(file_get_contents($dir.'/settings.json'), true, 512, JSON_THROW_ON_ERROR) : [];
        $config = ['data_dir' => $dir,
            'mail_transport' => getenv('NIAG_MAIL_TRANSPORT') ?: ($settings['mailTransport'] ?? 'mail'),
            'secure_cookie' => getenv('NIAG_LOCAL_HTTP') === '1' ? false : ($settings['secureCookie'] ?? true)];
    }
    return $config;
}
function data_path(string $path = ''): string { return rtrim(config()['data_dir'], '/').'/'.$path; }
function uid(): string { return bin2hex(random_bytes(16)); }
function fail(string $message, int $status = 400): never { throw new AppError($message, $status); }
class AppError extends RuntimeException {}
function headers_private(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache'); header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
}
function start_session(): void {
    headers_private();
    $dir = data_path('sessions');
    storage_mkdir($dir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '2592000');
    session_save_path($dir);
    session_name('niag_session');
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/').'/';
    session_set_cookie_params(['lifetime' => 2592000, 'path' => $path,
        'secure' => config()['secure_cookie'], 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    $_SESSION['csrf'] ??= uid();
}
require_once __DIR__.'/storage.php';
function text_value(mixed $v, int $max = 4000): string {
    if (!is_string($v) || strlen(trim($v)) > $max || str_contains($v, "\0")) fail('Ogiltigt textvärde.');
    return trim($v);
}
function required(mixed $v, int $max = 4000): string { $s = text_value($v, $max); if ($s === '') fail('Fyll i alla obligatoriska fält.'); return $s; }
function email_value(mixed $v): string {
    $s = strtolower(required($v, 254));
    if (!filter_var($s, FILTER_VALIDATE_EMAIL)) fail('Ange en giltig e-postadress.');
    return $s;
}
function integer(mixed $v, int $min, int $max): int {
    if (filter_var($v, FILTER_VALIDATE_INT) === false || $v < $min || $v > $max) fail("Ange ett heltal mellan $min och $max.");
    return (int)$v;
}
function translated(mixed $v, int $max = 4000): array {
    if (!is_array($v)) fail('Svensk och engelsk text krävs.');
    return ['sv' => required($v['sv'] ?? null, $max), 'en' => required($v['en'] ?? null, $max)];
}
function index_of(array $items, string $id): int {
    foreach ($items as $i => $v) if ($v['id'] === $id) return $i;
    fail('Objektet finns inte.', 404);
}
function rate_limit(array &$db, string $bucket, int $limit, int $seconds): void {
    // Shared IP abuse protection is a small locked text counter, not shared test JSON.
    $key = hash('sha256', $bucket);
    storage_lock('rate:'.$key, function () use ($key,$limit,$seconds) {
        storage_mkdir(data_path('rate-limits'));
        $file = data_path('rate-limits/'.$key.'.counter');
        $parts = is_file($file) ? explode(' ', trim(file_get_contents($file))) : [];
        $until = (int)($parts[0] ?? 0); $count = (int)($parts[1] ?? 0);
        if ($until <= time()) { $until = time()+$seconds; $count = 0; }
        if ($count >= $limit) fail('För många försök. Vänta en stund och försök igen.', 429);
        if (file_put_contents($file, $until.' '.($count+1)) === false) fail('Kunde inte spara åtkomstbegränsningen.', 503);
    });
}
function admin_email(array $db): ?string {
    $key = $_SESSION['adminToken'] ?? '';
    $s = $db['adminSessions'][$key] ?? null;
    return $s && $s['expires'] > time() && in_array($s['email'], $db['admins'], true) ? $s['email'] : null;
}
function require_admin(array $db): string { return admin_email($db) ?? fail('Logga in som administratör.', 401); }
require_once __DIR__.'/mail.php';
