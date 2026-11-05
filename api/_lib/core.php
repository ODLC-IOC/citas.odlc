<?php
// OdlC · núcleo del sistema de reuniones (Hostinger, PHP 8).
// Configuración y datos en public_html/_datos (fuera de Git y bloqueado por .htaccess).
// Ningún secreto está en el código: se leen de _datos/config.php.
declare(strict_types=1);

const ODLC_NOMBRE = 'Isabel Olivares de la Cerda';
const ODLC_EMPRESA = 'OdlC';
const ODLC_EMAIL = 'isabel@odlc.es';
const ODLC_WEB = 'https://odlc.es';
const ODLC_TZ = 'Europe/Madrid';
const DURACIONES = [15, 30, 45, 60, 90];
const MAX_FILE = 10 * 1024 * 1024;
const MAX_MATERIALS = 8;
const V = '20261105b';

date_default_timezone_set(ODLC_TZ);

function data_dir(): string {
    $d = getenv('ODLC_DATA_DIR') ?: dirname(__DIR__, 3) . '/_datos';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

function cfg(?string $key = null, $default = '') {
    static $c = null;
    if ($c === null) {
        $f = data_dir() . '/config.php';
        $c = is_file($f) ? (include $f) : [];
        if (!is_array($c)) $c = [];
    }
    if ($key === null) return $c;
    $v = $c[$key] ?? '';
    return ($v === '' || $v === null) ? $default : $v;
}

function site_url(): string { return rtrim((string)cfg('site_url', ODLC_WEB), '/'); }
function admin_email(): string { return strtolower(trim((string)cfg('admin_email', ODLC_EMAIL))); }

// ---------- Almacén en archivos JSON ----------
function path_for(string $key): string {
    if (!preg_match('#^[A-Za-z0-9_/-]+$#', $key) || str_contains($key, '..')) throw new RuntimeException('Clave no válida');
    return data_dir() . '/' . $key;
}
function get_json(string $key): ?array {
    $f = path_for($key) . '.json';
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function set_json(string $key, array $value): void {
    $f = path_for($key) . '.json';
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0750, true);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $f);
}
function del_key(string $key): void {
    $f = path_for($key) . '.json';
    if (is_file($f)) unlink($f);
}
function list_meetings(): array {
    $out = [];
    foreach (glob(data_dir() . '/m/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) $out[] = $d;
    }
    return $out;
}
function file_path(string $mid, string $fid): string { return path_for("f/$mid") . '/' . $fid; }

// ---------- HTTP ----------
function base_headers(): void {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}
function send_json($data, int $status = 200): never {
    http_response_code($status);
    base_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function send_html(string $body, int $status = 200): never {
    http_response_code($status);
    base_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    echo $body;
    exit;
}
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'desconocida'; }
function same_origin(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin === '') return ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin';
    return parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') === $host;
}
function body_json(): array {
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }

// ---------- Acceso (solo con cuenta de Microsoft autorizada) ----------
// No hay contraseñas: se entra con «Iniciar sesión con Microsoft» y solo si el correo está en la lista de autorizados.
const COOKIE = '__Host-odlc_s';
const SESSION_HOURS = 8;

function missing_config(): array {
    $m = [];
    if (strlen((string)cfg('session_secret')) < 32) $m[] = 'session_secret';
    foreach (['ms_tenant_id', 'ms_client_id', 'ms_client_secret'] as $k) if (cfg($k) === '') $m[] = $k;
    return $m;
}
function sign(string $v): string { return b64u(hash_hmac('sha256', $v, (string)cfg('session_secret'), true)); }

// Usuarios autorizados: la administradora siempre; el resto los añade ella desde el panel
function users(): array {
    $list = get_json('usuarios')['usuarios'] ?? [];
    $out = [admin_email() => ['email' => admin_email(), 'nombre' => ODLC_NOMBRE, 'admin' => true]];
    foreach ($list as $u) { $e = strtolower(trim((string)($u['email'] ?? ''))); if ($e && !isset($out[$e])) $out[$e] = ['email' => $e, 'nombre' => (string)($u['nombre'] ?? ''), 'admin' => false, 'alta' => $u['alta'] ?? null]; }
    return $out;
}
function save_users(array $list): void {
    set_json('usuarios', ['usuarios' => array_values(array_filter($list, fn($u) => $u['email'] !== admin_email()))]);
}

function session_cookie(string $email): string {
    $payload = b64u(json_encode(['u' => $email, 'exp' => (time() + SESSION_HOURS * 3600) * 1000, 'n' => b64u(random_bytes(9))]));
    return COOKIE . '=' . $payload . '.' . sign($payload) . '; Path=/; Max-Age=' . (SESSION_HOURS * 3600) . '; HttpOnly; Secure; SameSite=Strict';
}
function clear_cookie(): string { return COOKIE . '=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Strict'; }

// Devuelve el usuario con sesión válida (y que siga autorizado) o null
function current_user(): ?array {
    if (missing_config()) return null;
    $parts = explode('.', $_COOKIE[COOKIE] ?? '');
    if (count($parts) !== 2 || !hash_equals(sign($parts[0]), $parts[1])) return null;
    $d = json_decode(b64u_dec($parts[0]), true);
    if (!is_array($d) || ($d['exp'] ?? 0) <= time() * 1000) return null;
    return users()[strtolower((string)($d['u'] ?? ''))] ?? null;
}
function is_authenticated(): bool { return current_user() !== null; }
