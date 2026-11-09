<?php
/**
 * MDTV+ STUDIO — Cabina de Control del Director
 * PHP 8+ nativo · Sesión endurecida · CSRF · Rate-limit · Escribe data/config.json
 */

declare(strict_types=1);

/* ========== Sesión endurecida (antes de session_start) ========== */
$mdtvHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_trans_sid', '0');
if ($mdtvHttps) {
    ini_set('session.cookie_secure', '1');
}

session_name('MDTVSTUDIO');
session_start();

/* ========== CONFIGURACIÓN MAESTRA ========== */
// Hash bcrypt de la clave actual. Para cambiarla: password_hash('NUEVA', PASSWORD_DEFAULT)
const ADMIN_PASSWORD_HASH = '$2y$12$WOD0KhUSH4X6NVpcZD2RjegnzmOvFFpF6/OCK5RgAUf2Rjmha3tJe';
const CONFIG_PATH         = __DIR__ . '/data/config.json';
const RATE_LIMIT_PATH     = __DIR__ . '/data/login_attempts.json';
const SESSION_KEY         = 'mdtv_studio_auth';
const CSRF_KEY            = 'mdtv_studio_csrf';
const SESSION_TS_KEY      = 'mdtv_studio_auth_at';
const MAX_LOGIN_ATTEMPTS  = 5;
const LOCKOUT_SECONDS     = 900;  // 15 min
const SESSION_IDLE_SECONDS = 7200; // 2 h

/* ---------- Helpers ---------- */

function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: https://i.ytimg.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; "
        . "script-src 'self' 'unsafe-inline'; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

send_security_headers();

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function load_rate_limit(): array
{
    if (!is_file(RATE_LIMIT_PATH)) {
        return [];
    }
    $data = json_decode((string)file_get_contents(RATE_LIMIT_PATH), true);
    return is_array($data) ? $data : [];
}

function save_rate_limit(array $data): void
{
    $dir = dirname(RATE_LIMIT_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = RATE_LIMIT_PATH . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) {
        return;
    }
    rename($tmp, RATE_LIMIT_PATH);
}

function login_lock_status(): array
{
    $ip = client_ip();
    $store = load_rate_limit();
    $entry = $store[$ip] ?? ['fails' => 0, 'locked_until' => 0, 'window_start' => 0];
    $now = time();
    $lockedUntil = (int)($entry['locked_until'] ?? 0);

    if ($lockedUntil > $now) {
        return [
            'locked'    => true,
            'remaining' => $lockedUntil - $now,
            'fails'     => (int)($entry['fails'] ?? 0),
        ];
    }

    $windowStart = (int)($entry['window_start'] ?? 0);
    if ($windowStart > 0 && ($now - $windowStart) > LOCKOUT_SECONDS) {
        return ['locked' => false, 'remaining' => 0, 'fails' => 0];
    }

    return [
        'locked'    => false,
        'remaining' => 0,
        'fails'     => (int)($entry['fails'] ?? 0),
    ];
}

function register_login_failure(): array
{
    $ip = client_ip();
    $store = load_rate_limit();
    $now = time();
    $entry = $store[$ip] ?? ['fails' => 0, 'window_start' => $now, 'locked_until' => 0];

    if ((int)($entry['locked_until'] ?? 0) > $now) {
        return login_lock_status();
    }

    if (($now - (int)($entry['window_start'] ?? $now)) > LOCKOUT_SECONDS) {
        $entry = ['fails' => 0, 'window_start' => $now, 'locked_until' => 0];
    }
    if ((int)($entry['window_start'] ?? 0) === 0) {
        $entry['window_start'] = $now;
    }

    $entry['fails'] = (int)$entry['fails'] + 1;
    if ($entry['fails'] >= MAX_LOGIN_ATTEMPTS) {
        $entry['locked_until'] = $now + LOCKOUT_SECONDS;
    }

    $store[$ip] = $entry;

    foreach ($store as $k => $v) {
        $lu = (int)($v['locked_until'] ?? 0);
        $ws = (int)($v['window_start'] ?? 0);
        if ($lu < $now && ($now - $ws) > (LOCKOUT_SECONDS * 2)) {
            unset($store[$k]);
        }
    }

    save_rate_limit($store);
    return login_lock_status();
}

function clear_login_failures(): void
{
    $ip = client_ip();
    $store = load_rate_limit();
    if (isset($store[$ip])) {
        unset($store[$ip]);
        save_rate_limit($store);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION[CSRF_KEY]) || !is_string($_SESSION[CSRF_KEY])) {
        $_SESSION[CSRF_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_KEY];
}

function verify_csrf(?string $token): bool
{
    $session = $_SESSION[CSRF_KEY] ?? '';
    return is_string($session)
        && $session !== ''
        && is_string($token)
        && $token !== ''
        && hash_equals($session, $token);
}

function request_csrf_token(): ?string
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($header) && $header !== '') {
        return $header;
    }
    if (isset($_POST['csrf_token'])) {
        return (string)$_POST['csrf_token'];
    }
    return null;
}

function require_csrf_or_fail(bool $asJson = true): void
{
    if (verify_csrf(request_csrf_token())) {
        return;
    }
    if ($asJson) {
        json_response(['ok' => false, 'error' => 'Token de seguridad inválido. Recarga la página.'], 403);
    }
    http_response_code(403);
    exit('Forbidden');
}

function extract_youtube_id(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
        return $input;
    }
    $patterns = [
        '/(?:youtube\.com\/watch\?[^#]*v=|m\.youtube\.com\/watch\?[^#]*v=|youtu\.be\/|youtube\.com\/live\/|youtube\.com\/embed\/|youtube\.com\/shorts\/|youtube\.com\/v\/)([a-zA-Z0-9_-]{11})/',
        '/[?&]v=([a-zA-Z0-9_-]{11})/',
        '/youtu\.be\/([a-zA-Z0-9_-]{11})/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input, $m)) {
            return $m[1];
        }
    }
    return '';
}

function load_config(): array
{
    if (!is_file(CONFIG_PATH)) {
        return [];
    }
    $raw = file_get_contents(CONFIG_PATH);
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function save_config(array $config): bool
{
    $dir = dirname(CONFIG_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode(
        $config,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($json === false) {
        return false;
    }
    $tmp = CONFIG_PATH . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, CONFIG_PATH);
}

function is_authenticated(): bool
{
    if (empty($_SESSION[SESSION_KEY])) {
        return false;
    }
    $ts = (int)($_SESSION[SESSION_TS_KEY] ?? 0);
    if ($ts > 0 && (time() - $ts) > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        return false;
    }
    $_SESSION[SESSION_TS_KEY] = time();
    return true;
}

function normalize_whatsapp_link(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
        return $value;
    }
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return $value;
    }
    return 'https://wa.me/' . $digits;
}

function datetime_local_to_iso(string $local): string
{
    $local = trim($local);
    if ($local === '') {
        return '';
    }
    // datetime-local: 2026-11-08T16:00 → ISO Chile -03:00
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
        return $local . ':00-03:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $local)) {
        return $local . '-03:00';
    }
    return $local;
}

function iso_to_datetime_local(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return substr($iso, 0, 16);
    }
}

function merge_incoming_config(array $base, array $incoming): array
{
    // Preserve channel_info always
    $out = $base;

    if (isset($incoming['channel_info']) && is_array($incoming['channel_info'])) {
        $out['channel_info'] = array_merge(
            $base['channel_info'] ?? [],
            $incoming['channel_info']
        );
    }

    if (isset($incoming['live_stream']) && is_array($incoming['live_stream'])) {
        $lsIn = $incoming['live_stream'];
        $lsBase = $base['live_stream'] ?? [];

        $ytRaw = (string)($lsIn['youtube_url'] ?? $lsIn['youtube_id'] ?? '');
        $ytId = extract_youtube_id($ytRaw);
        if ($ytId === '' && !empty($lsBase['youtube_id'])) {
            $ytId = (string)$lsBase['youtube_id'];
        }

        $nextIso = (string)($lsIn['next_match_iso'] ?? '');
        if (isset($lsIn['next_match_datetime']) && $lsIn['next_match_datetime'] !== '') {
            $nextIso = datetime_local_to_iso((string)$lsIn['next_match_datetime']);
        }

        $home = array_merge($lsBase['home_team'] ?? [], is_array($lsIn['home_team'] ?? null) ? $lsIn['home_team'] : []);
        $away = array_merge($lsBase['away_team'] ?? [], is_array($lsIn['away_team'] ?? null) ? $lsIn['away_team'] : []);

        $home['short'] = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)($home['short'] ?? 'LOC')) ?: 'LOC', 0, 3));
        $away['short'] = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)($away['short'] ?? 'VIS')) ?: 'VIS', 0, 3));
        $home['score'] = max(0, (int)($home['score'] ?? 0));
        $away['score'] = max(0, (int)($away['score'] ?? 0));

        $out['live_stream'] = [
            'is_live'            => filter_var($lsIn['is_live'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'youtube_id'         => $ytId,
            'match_title'        => (string)($lsIn['match_title'] ?? ($lsBase['match_title'] ?? '')),
            'tournament'         => (string)($lsIn['tournament'] ?? ($lsBase['tournament'] ?? '')),
            'stadium'            => (string)($lsIn['stadium'] ?? ($lsBase['stadium'] ?? '')),
            'home_team'          => [
                'name'  => (string)($home['name'] ?? 'Local'),
                'short' => $home['short'],
                'color' => (string)($home['color'] ?? '#00843D'),
                'score' => $home['score'],
            ],
            'away_team'          => [
                'name'  => (string)($away['name'] ?? 'Visita'),
                'short' => $away['short'],
                'color' => (string)($away['color'] ?? '#0038A8'),
                'score' => $away['score'],
            ],
            'match_status_badge' => (string)($lsIn['match_status_badge'] ?? ($lsBase['match_status_badge'] ?? '')),
            'next_match_iso'     => $nextIso !== '' ? $nextIso : (string)($lsBase['next_match_iso'] ?? ''),
            'next_match_title'   => (string)($lsIn['next_match_title'] ?? ($lsBase['next_match_title'] ?? '')),
        ];
    }

    if (isset($incoming['sponsors']) && is_array($incoming['sponsors'])) {
        $sponsors = [];
        foreach ($incoming['sponsors'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $name = trim((string)($s['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $tier = (string)($s['tier'] ?? 'gold');
            if (!in_array($tier, ['presenter', 'gold', 'available'], true)) {
                $tier = 'gold';
            }
            $logoUrl = trim((string)($s['logo_url'] ?? ''));
            // Solo rutas locales de assets o URLs http(s) absolutas
            if ($logoUrl !== '' && !preg_match('#^(assets/(uploads|img)/[a-zA-Z0-9._/-]+|https?://)#', $logoUrl)) {
                $logoUrl = '';
            }
            $phone = preg_replace('/[^\d+]/', '', (string)($s['phone'] ?? '')) ?? '';

            $sponsors[] = [
                'name'     => $name,
                'category' => (string)($s['category'] ?? 'Auspiciador'),
                'cta'      => (string)($s['cta'] ?? ''),
                'phone'    => $phone,
                'whatsapp' => normalize_whatsapp_link((string)($s['whatsapp'] ?? '')),
                'tier'     => $tier,
                'logo_url' => $logoUrl,
            ];
        }
        $out['sponsors'] = $sponsors;
    }

    if (isset($incoming['upcoming_matches']) && is_array($incoming['upcoming_matches'])) {
        $upcoming = [];
        foreach ($incoming['upcoming_matches'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $home = trim((string)($m['home'] ?? ''));
            $away = trim((string)($m['away'] ?? ''));
            if ($home === '' && $away === '') {
                continue;
            }
            $iso = (string)($m['iso'] ?? '');
            if (!empty($m['datetime_local'])) {
                $iso = datetime_local_to_iso((string)$m['datetime_local']);
            }
            $date = (string)($m['date'] ?? '');
            $time = (string)($m['time'] ?? '');
            if ($iso !== '') {
                try {
                    $dt = new DateTimeImmutable($iso);
                    $date = $dt->format('Y-m-d');
                    $time = $dt->format('H:i');
                } catch (Throwable) {
                    // keep provided
                }
            }
            $upcoming[] = [
                'date'       => $date,
                'time'       => $time,
                'home'       => $home,
                'away'       => $away,
                'tournament' => (string)($m['tournament'] ?? ''),
                'stadium'    => (string)($m['stadium'] ?? ''),
                'iso'        => $iso,
                'featured'   => filter_var($m['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        // Solo un partido "más esperado"
        $featuredIdx = -1;
        foreach ($upcoming as $i => $row) {
            if (!empty($row['featured'])) {
                $featuredIdx = $i;
            }
        }
        if ($featuredIdx >= 0) {
            foreach ($upcoming as $i => $_) {
                $upcoming[$i]['featured'] = ($i === $featuredIdx);
            }
        }
        $out['upcoming_matches'] = $upcoming;
    }

    if (isset($incoming['vod_matches']) && is_array($incoming['vod_matches'])) {
        $prevVods = is_array($base['vod_matches'] ?? null) ? $base['vod_matches'] : [];
        $vods = [];
        foreach ($incoming['vod_matches'] as $idx => $v) {
            if (!is_array($v)) {
                continue;
            }
            $title = trim((string)($v['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $ytRaw = trim((string)($v['youtube_url'] ?? $v['youtube_id'] ?? ''));
            $ytId  = extract_youtube_id($ytRaw);
            // Si el link nuevo no se pudo leer, conservar el ID anterior (no borrar el partido)
            if ($ytId === '' && !empty($prevVods[$idx]['youtube_id'])) {
                $ytId = extract_youtube_id((string)$prevVods[$idx]['youtube_id']);
            }
            if ($ytId === '') {
                continue;
            }
            $thumb = trim((string)($v['thumbnail'] ?? ''));
            if ($thumb === '' || str_contains($thumb, 'unsplash.com') || str_contains($thumb, '/vi/')) {
                $thumb = 'https://i.ytimg.com/vi/' . $ytId . '/hqdefault.jpg';
            }
            $vods[] = [
                'title'      => $title,
                'date'       => (string)($v['date'] ?? date('Y-m-d')),
                'tournament' => (string)($v['tournament'] ?? ''),
                'thumbnail'  => $thumb,
                'youtube_id' => $ytId,
            ];
        }
        $out['vod_matches'] = $vods;
    }

    return $out;
}

/* ---------- Request handlers ---------- */

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$loginError = '';
$lockStatus = login_lock_status();

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_or_fail(false);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(request_csrf_token())) {
        $loginError = 'Sesión inválida. Recarga la página e intenta de nuevo.';
    } elseif ($lockStatus['locked']) {
        $mins = max(1, (int)ceil($lockStatus['remaining'] / 60));
        $loginError = "Demasiados intentos fallidos. Espera {$mins} minuto(s) e intenta de nuevo.";
    } else {
        $pass = (string)($_POST['password'] ?? '');
        if ($pass !== '' && password_verify($pass, ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION[SESSION_KEY] = true;
            $_SESSION[SESSION_TS_KEY] = time();
            $_SESSION[CSRF_KEY] = bin2hex(random_bytes(32));
            clear_login_failures();
            header('Location: admin.php');
            exit;
        }
        $lockStatus = register_login_failure();
        if ($lockStatus['locked']) {
            $mins = max(1, (int)ceil($lockStatus['remaining'] / 60));
            $loginError = "Demasiados intentos. Acceso bloqueado por {$mins} minuto(s).";
        } else {
            $left = max(0, MAX_LOGIN_ATTEMPTS - (int)$lockStatus['fails']);
            $loginError = "Contraseña incorrecta. Te quedan {$left} intento(s).";
        }
    }
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_authenticated()) {
        json_response(['ok' => false, 'error' => 'No autorizado'], 401);
    }
    require_csrf_or_fail(true);
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '{}', true);
    if (!is_array($payload)) {
        json_response(['ok' => false, 'error' => 'JSON inválido'], 400);
    }
    $base = load_config();
    if ($base === []) {
        json_response(['ok' => false, 'error' => 'No se pudo leer config.json'], 500);
    }
    $merged = merge_incoming_config($base, $payload);
    if (!save_config($merged)) {
        json_response(['ok' => false, 'error' => 'No se pudo escribir config.json. Revisa permisos de /data'], 500);
    }
    json_response(['ok' => true, 'message' => 'Señal actualizada', 'config' => $merged]);
}

if ($action === 'upload_sponsor_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_authenticated()) {
        json_response(['ok' => false, 'error' => 'No autorizado'], 401);
    }
    require_csrf_or_fail(true);

    if (empty($_FILES['logo']) || !is_array($_FILES['logo'])) {
        json_response(['ok' => false, 'error' => 'No se recibió ninguna imagen'], 400);
    }

    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Error al subir el archivo (código ' . (int)$file['error'] . ')'], 400);
    }

    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        json_response(['ok' => false, 'error' => 'La imagen supera 3 MB'], 400);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_response(['ok' => false, 'error' => 'Archivo temporal inválido'], 400);
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmp) ?: '';
    }
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        json_response(['ok' => false, 'error' => 'Solo se permiten JPG, PNG o WEBP'], 400);
    }

    $uploadDir = __DIR__ . '/assets/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        json_response(['ok' => false, 'error' => 'No se pudo crear /assets/uploads'], 500);
    }

    $filename = 'sponsor_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        json_response(['ok' => false, 'error' => 'No se pudo guardar la imagen. Revisa permisos de /assets/uploads'], 500);
    }

    $publicPath = 'assets/uploads/' . $filename;
    json_response([
        'ok'   => true,
        'path' => $publicPath,
        'url'  => $publicPath,
    ]);
}

if ($action === 'config' && is_authenticated()) {
    json_response(['ok' => true, 'config' => load_config()]);
}

$config = load_config();
$loggedIn = is_authenticated();
$csrf = csrf_token();
$lockStatus = login_lock_status();
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
$csrfJson = json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$nextLocal = iso_to_datetime_local((string)($config['live_stream']['next_match_iso'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#07090E">
  <title>MDTV+ STUDIO — Cabina de Control</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link rel="apple-touch-icon" href="assets/img/logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #07090E;
      --card: #0F141F;
      --card2: #121826;
      --live: #E63946;
      --neon: #00E676;
      --text: #fff;
      --muted: #94A3B8;
      --dim: #64748B;
      --border: rgba(255,255,255,.08);
      --border2: rgba(255,255,255,.14);
      --radius: 14px;
      --font: "Inter", system-ui, sans-serif;
      --display: "Outfit", system-ui, sans-serif;
      --top: 64px;
      --safe-b: env(safe-area-inset-bottom, 0px);
      --ease: cubic-bezier(.22,1,.36,1);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { -webkit-text-size-adjust: 100%; }
    body {
      font-family: var(--font);
      background: var(--bg);
      color: var(--text);
      min-height: 100dvh;
      line-height: 1.45;
      background-image:
        radial-gradient(ellipse 70% 40% at 50% -10%, rgba(230,57,70,.14), transparent),
        radial-gradient(ellipse 50% 30% at 100% 0%, rgba(0,230,118,.05), transparent);
      padding-bottom: calc(88px + var(--safe-b));
    }
    button, input, select, textarea { font: inherit; color: inherit; }
    button { cursor: pointer; border: none; background: none; }
    a { color: inherit; text-decoration: none; }
    :focus-visible { outline: 2px solid var(--neon); outline-offset: 2px; }

    /* Login */
    .login {
      min-height: 100dvh;
      display: grid;
      place-items: center;
      padding: 24px 16px;
    }
    .login__card {
      width: 100%;
      max-width: 400px;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 28px 22px;
      box-shadow: 0 24px 48px rgba(0,0,0,.4);
    }
    .login__brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      margin-bottom: 6px;
      text-align: center;
    }
    .login__brand img {
      width: 88px;
      height: 88px;
      object-fit: contain;
      border-radius: 16px;
    }
    .login__brand-text {
      font-family: var(--display);
      font-weight: 800;
      font-size: 1.25rem;
      letter-spacing: -.03em;
    }
    .login__brand-text span { color: var(--live); }
    .login__sub { color: var(--muted); font-size: .9rem; margin-bottom: 22px; text-align: center; }
    .login__error {
      background: rgba(230,57,70,.12);
      border: 1px solid rgba(230,57,70,.4);
      color: #ff8a93;
      padding: 10px 12px;
      border-radius: 8px;
      font-size: .85rem;
      margin-bottom: 14px;
    }
    .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
    .field label { font-size: .75rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    .field input, .field textarea, .field select {
      width: 100%;
      background: #0a0d14;
      border: 1px solid var(--border2);
      border-radius: 10px;
      padding: 14px 14px;
      font-size: 1rem;
      min-height: 48px;
      transition: border-color .2s;
    }
    .field textarea { min-height: 80px; resize: vertical; }
    .field input:focus, .field textarea:focus, .field select:focus {
      border-color: rgba(0,230,118,.45);
      outline: none;
    }
    .btn-primary {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      width: 100%; min-height: 52px; padding: 14px 18px;
      background: var(--live); color: #fff; font-weight: 700;
      border-radius: 12px; font-size: 1rem;
      transition: filter .2s, transform .2s var(--ease);
    }
    .btn-primary:hover { filter: brightness(1.08); }
    .btn-primary:active { transform: scale(.98); }
    .btn-primary:disabled { opacity: .55; cursor: wait; }
    .btn-ghost {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      min-height: 44px; padding: 10px 14px;
      border: 1px solid var(--border2); border-radius: 10px;
      color: var(--muted); font-weight: 600; font-size: .85rem;
      background: rgba(255,255,255,.03);
    }
    .btn-ghost:hover { color: var(--text); border-color: var(--muted); }

    /* Topbar */
    .topbar {
      position: sticky; top: 0; z-index: 40;
      height: var(--top);
      display: flex; align-items: center; justify-content: space-between; gap: 10px;
      padding: 0 12px;
      background: rgba(15,20,31,.86);
      backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
      border-bottom: 1px solid var(--border);
    }
    .topbar__brand {
      font-family: var(--display); font-weight: 800; font-size: 1rem;
      letter-spacing: -.02em; display: flex; align-items: center; gap: 8px; min-width: 0;
    }
    .topbar__brand img {
      width: 36px; height: 36px; object-fit: contain; border-radius: 8px; flex-shrink: 0;
    }
    .topbar__brand b { color: var(--live); }
    .status-pill {
      display: inline-flex; align-items: center; gap: 6px;
      font-size: .65rem; font-weight: 700; letter-spacing: .04em;
      padding: 5px 9px; border-radius: 999px; white-space: nowrap;
    }
    .status-pill.is-live {
      background: rgba(230,57,70,.15); color: #ff8a93;
      border: 1px solid rgba(230,57,70,.4);
    }
    .status-pill.is-previa {
      background: rgba(0,230,118,.1); color: var(--neon);
      border: 1px solid rgba(0,230,118,.35);
    }
    .dot {
      width: 7px; height: 7px; border-radius: 50%; background: currentColor;
      animation: pulse 1.5s ease-in-out infinite;
    }
    @keyframes pulse {
      0%,100% { opacity: 1; box-shadow: 0 0 0 0 currentColor; }
      50% { opacity: .7; box-shadow: 0 0 0 6px transparent; }
    }
    .topbar__actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }

    /* Layout */
    .wrap { max-width: 720px; margin: 0 auto; padding: 14px 12px 24px; }
    .tabs {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;
      margin-bottom: 14px; position: sticky; top: var(--top); z-index: 30;
      background: rgba(7,9,14,.92); backdrop-filter: blur(10px);
      padding: 8px 0;
    }
    .tabs__btn {
      min-height: 46px; padding: 8px 6px; border-radius: 10px;
      font-size: .72rem; font-weight: 700; color: var(--dim);
      border: 1px solid var(--border); background: var(--card);
    }
    .tabs__btn.is-active {
      color: var(--text); border-color: rgba(230,57,70,.45);
      background: rgba(230,57,70,.12);
    }
    .panel[hidden] { display: none !important; }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 16px;
      margin-bottom: 12px;
      box-shadow: 0 8px 24px rgba(0,0,0,.25);
    }
    .card__title {
      font-family: var(--display); font-weight: 700; font-size: 1.05rem;
      margin-bottom: 4px;
    }
    .card__hint { color: var(--muted); font-size: .8rem; margin-bottom: 14px; }

    /* Master switch */
    .master {
      display: flex; flex-direction: column; gap: 14px;
      padding: 4px 0 8px;
    }
    .master__row {
      display: flex; align-items: center; justify-content: space-between; gap: 14px;
    }
    .master__label { font-family: var(--display); font-weight: 800; font-size: 1.05rem; }
    .master__sub { font-size: .8rem; color: var(--muted); margin-top: 2px; }
    .switch {
      position: relative; width: 72px; height: 42px; flex-shrink: 0;
      border-radius: 999px; background: #1a2233; border: 2px solid var(--border2);
      transition: background .25s, border-color .25s;
    }
    .switch.is-on { background: var(--live); border-color: #ff6b76; }
    .switch__knob {
      position: absolute; top: 3px; left: 3px;
      width: 32px; height: 32px; border-radius: 50%;
      background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.35);
      transition: transform .25s var(--ease);
    }
    .switch.is-on .switch__knob { transform: translateX(30px); }

    .yt-preview {
      display: none; margin-top: 10px; border-radius: 10px; overflow: hidden;
      border: 1px solid var(--border); background: #000; aspect-ratio: 16/9;
    }
    .yt-preview.is-visible { display: block; }
    .yt-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .yt-id {
      margin-top: 8px; font-size: .75rem; color: var(--neon);
      font-family: ui-monospace, monospace;
    }

    .badge-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;
    }
    .badge-chip {
      min-height: 48px; padding: 8px 10px; border-radius: 10px;
      border: 1px solid var(--border2); background: #0a0d14;
      font-size: .72rem; font-weight: 700; text-align: center; color: var(--muted);
    }
    .badge-chip.is-active {
      color: #fff; border-color: rgba(230,57,70,.5);
      background: rgba(230,57,70,.18);
    }

    /* Score columns */
    .score-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
    }
    .team-box {
      background: var(--card2); border: 1px solid var(--border);
      border-radius: 12px; padding: 12px;
    }
    .team-box__head {
      font-size: .7rem; font-weight: 700; letter-spacing: .08em;
      text-transform: uppercase; color: var(--dim); margin-bottom: 10px; text-align: center;
    }
    .score-ctrl {
      display: flex; align-items: center; justify-content: center; gap: 10px;
      margin: 8px 0 12px;
    }
    .score-btn {
      width: 52px; height: 52px; border-radius: 14px;
      background: #0a0d14; border: 1px solid var(--border2);
      font-family: var(--display); font-size: 1.6rem; font-weight: 800;
      color: var(--text); line-height: 1;
      transition: background .15s, transform .15s;
      touch-action: manipulation; user-select: none;
    }
    .score-btn:active { transform: scale(.94); background: rgba(230,57,70,.2); }
    .score-val {
      font-family: var(--display); font-weight: 800; font-size: 2.4rem;
      min-width: 1.4ch; text-align: center; line-height: 1;
    }
    .color-row { display: flex; align-items: center; gap: 8px; }
    .color-row input[type="color"] {
      width: 48px; height: 40px; border: 1px solid var(--border2);
      border-radius: 8px; background: transparent; padding: 2px;
    }

    .item-card {
      background: var(--card2); border: 1px solid var(--border);
      border-radius: 12px; padding: 12px; margin-bottom: 10px; position: relative;
    }
    .item-card__actions {
      display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 8px;
    }
    .item-card.is-featured {
      border-color: rgba(0, 230, 118, 0.45);
      box-shadow: 0 0 0 1px rgba(0, 230, 118, 0.2);
    }
    .btn-featured {
      display: inline-flex; align-items: center; gap: 6px;
      min-height: 40px; padding: 8px 12px; border-radius: 8px;
      border: 1px solid var(--border2); color: var(--muted);
      background: rgba(255,255,255,.03); font-weight: 700; font-size: .8rem;
    }
    .btn-featured.is-on {
      color: #04140a; background: var(--neon); border-color: var(--neon);
    }
    .btn-danger {
      min-height: 40px; padding: 8px 12px; border-radius: 8px;
      background: rgba(230,57,70,.12); border: 1px solid rgba(230,57,70,.35);
      color: #ff8a93; font-weight: 600; font-size: .8rem;
    }
    .btn-add {
      width: 100%; min-height: 48px; border-radius: 12px;
      border: 1px dashed rgba(0,230,118,.4); color: var(--neon);
      font-weight: 700; background: rgba(0,230,118,.05); margin-top: 4px;
    }

    /* Sticky save bar */
    .savebar {
      position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
      padding: 10px 12px calc(10px + var(--safe-b));
      background: rgba(7,9,14,.94);
      backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
      border-top: 1px solid var(--border);
      display: flex; gap: 8px;
    }
    .savebar .btn-primary { flex: 1; }
    .savebar .btn-ghost { flex-shrink: 0; }

    /* Toast */
    .toast {
      position: fixed; left: 50%; bottom: calc(90px + var(--safe-b));
      transform: translateX(-50%) translateY(20px);
      z-index: 80; max-width: min(92vw, 420px);
      background: #053820; border: 1px solid rgba(0,230,118,.55);
      color: #b8ffd6; padding: 14px 18px; border-radius: 12px;
      font-weight: 700; font-size: .9rem; text-align: center;
      box-shadow: 0 12px 40px rgba(0,230,118,.2);
      opacity: 0; pointer-events: none;
      transition: opacity .3s, transform .3s var(--ease);
    }
    .toast.is-show {
      opacity: 1; transform: translateX(-50%) translateY(0);
    }
    .toast.is-error {
      background: #3a0d12; border-color: rgba(230,57,70,.55); color: #ffb4ba;
      box-shadow: 0 12px 40px rgba(230,57,70,.2);
    }

    .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .section-label {
      font-size: .7rem; font-weight: 700; letter-spacing: .1em;
      text-transform: uppercase; color: var(--neon); margin: 16px 0 10px;
    }
    .vod-thumb {
      width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px;
      background: #000; margin-top: 8px; display: none;
    }
    .vod-thumb.is-visible { display: block; }

    .logo-box {
      background: #0a0d14; border: 1px dashed var(--border2);
      border-radius: 10px; padding: 12px; margin-bottom: 10px;
    }
    .logo-box__preview {
      width: 100%; max-height: 96px; object-fit: contain;
      background: rgba(255,255,255,.04); border-radius: 8px;
      margin-bottom: 10px; display: none;
    }
    .logo-box__preview.is-visible { display: block; }
    .logo-box__row { display: flex; flex-wrap: wrap; gap: 8px; }
    .logo-box__row .btn-ghost, .logo-box__row .btn-upload {
      flex: 1; min-width: 140px; min-height: 46px;
    }
    .btn-upload {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      min-height: 46px; padding: 10px 14px; border-radius: 10px;
      background: rgba(0,230,118,.1); border: 1px solid rgba(0,230,118,.35);
      color: var(--neon); font-weight: 700; font-size: .8rem;
    }
    .btn-upload:disabled { opacity: .55; cursor: wait; }
    .logo-box input[type="file"] { display: none; }

    @media (min-width: 640px) {
      .badge-grid { grid-template-columns: repeat(3, 1fr); }
      .tabs__btn { font-size: .8rem; }
    }
  </style>
</head>
<body>

<?php if (!$loggedIn): ?>
  <div class="login">
    <form class="login__card" method="post" action="admin.php" autocomplete="off">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="login__brand">
        <img src="assets/img/logo.png" alt="MDTV+ Streaming" width="88" height="88">
        <div class="login__brand-text">MDTV<span>+</span> STUDIO</div>
      </div>
      <p class="login__sub">Cabina de control del director · Acceso restringido</p>
      <?php if (!empty($loginError)): ?>
        <div class="login__error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
      <?php elseif (!empty($lockStatus['locked'])): ?>
        <div class="login__error">Acceso temporalmente bloqueado por intentos fallidos. Espera <?= (int)max(1, (int)ceil(((int)$lockStatus['remaining']) / 60)) ?> minuto(s).</div>
      <?php endif; ?>
      <div class="field">
        <label for="password">Contraseña maestra</label>
        <input type="password" id="password" name="password" required autofocus autocomplete="current-password" placeholder="••••••••" <?= !empty($lockStatus['locked']) ? 'disabled' : '' ?>>
      </div>
      <button type="submit" class="btn-primary" <?= !empty($lockStatus['locked']) ? 'disabled' : '' ?>>Entrar a la Cabina</button>
    </form>
  </div>
<?php else: ?>

  <header class="topbar">
    <div class="topbar__brand">
      <img src="assets/img/logo.png" alt="MDTV+" width="36" height="36">
      MDTV<b>+</b> STUDIO
      <span class="status-pill is-live" id="air-status"><span class="dot"></span><span id="air-status-text">AL AIRE</span></span>
    </div>
    <div class="topbar__actions">
      <a class="btn-ghost" href="index.html" target="_blank" rel="noopener">Ver Web ↗</a>
      <form method="post" action="admin.php" style="display:inline;margin:0">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn-ghost">Salir</button>
      </form>
    </div>
  </header>

  <div class="wrap">
    <nav class="tabs" role="tablist">
      <button type="button" class="tabs__btn is-active" data-tab="live" role="tab" aria-selected="true">En Vivo &amp; Marcador</button>
      <button type="button" class="tabs__btn" data-tab="sponsors" role="tab" aria-selected="false">Auspiciadores</button>
      <button type="button" class="tabs__btn" data-tab="schedule" role="tab" aria-selected="false">Cartelera &amp; VOD</button>
    </nav>

    <!-- TAB 1 -->
    <div class="panel" id="panel-live" role="tabpanel">
      <section class="card">
        <h2 class="card__title">Switch Maestro de Transmisión</h2>
        <p class="card__hint">El botón rojo. Actívalo cuando la señal de YouTube esté al aire.</p>

        <div class="master">
          <div class="master__row">
            <div>
              <div class="master__label" id="master-label">🔴 SEÑAL EN VIVO ACTIVA</div>
              <div class="master__sub" id="master-sub">Los hinchas ven el reproductor ahora</div>
            </div>
            <button type="button" class="switch is-on" id="is-live-switch" role="switch" aria-checked="true" aria-label="Alternar señal en vivo">
              <span class="switch__knob"></span>
            </button>
          </div>

          <div class="field">
            <label for="youtube-url">Link de YouTube del Partido</label>
            <input type="url" id="youtube-url" placeholder="Pega aquí el link completo de YouTube" inputmode="url" autocomplete="off">
            <div class="yt-id" id="yt-id-hint" hidden></div>
            <div class="yt-preview" id="yt-preview"><img id="yt-thumb" alt="Vista previa del partido"></div>
          </div>

          <div class="field" style="margin-bottom:8px">
            <label>Estado del partido (1 toque)</label>
            <div class="badge-grid" id="badge-grid">
              <button type="button" class="badge-chip" data-badge="EN VIVO • PREVIA">🔴 EN VIVO • PREVIA</button>
              <button type="button" class="badge-chip" data-badge="EN VIVO • 1° TIEMPO">🔴 EN VIVO • 1° TIEMPO</button>
              <button type="button" class="badge-chip" data-badge="ENTRETIEMPO">⏸️ ENTRETIEMPO</button>
              <button type="button" class="badge-chip" data-badge="EN VIVO • 2° TIEMPO">🔴 EN VIVO • 2° TIEMPO</button>
              <button type="button" class="badge-chip" data-badge="FINALIZADO">🏁 FINALIZADO</button>
            </div>
            <input type="text" id="match-status-badge" placeholder="O escribe texto libre…" maxlength="60">
          </div>
        </div>
      </section>

      <section class="card">
        <h2 class="card__title">Marcador en Vivo</h2>
        <p class="card__hint">Subí o bajá goles con un dedo. Ideal desde la tribuna.</p>

        <div class="score-grid">
          <div class="team-box">
            <div class="team-box__head">Local</div>
            <div class="score-ctrl">
              <button type="button" class="score-btn" data-score="home" data-delta="-1" aria-label="Bajar gol local">−</button>
              <span class="score-val" id="home-score">0</span>
              <button type="button" class="score-btn" data-score="home" data-delta="1" aria-label="Subir gol local">+</button>
            </div>
            <div class="field">
              <label for="home-name">Nombre</label>
              <input id="home-name" type="text" maxlength="40">
            </div>
            <div class="row-2">
              <div class="field">
                <label for="home-short">Sigla</label>
                <input id="home-short" type="text" maxlength="3" style="text-transform:uppercase">
              </div>
              <div class="field">
                <label for="home-color">Color</label>
                <div class="color-row"><input id="home-color" type="color"></div>
              </div>
            </div>
          </div>

          <div class="team-box">
            <div class="team-box__head">Visita</div>
            <div class="score-ctrl">
              <button type="button" class="score-btn" data-score="away" data-delta="-1" aria-label="Bajar gol visita">−</button>
              <span class="score-val" id="away-score">0</span>
              <button type="button" class="score-btn" data-score="away" data-delta="1" aria-label="Subir gol visita">+</button>
            </div>
            <div class="field">
              <label for="away-name">Nombre</label>
              <input id="away-name" type="text" maxlength="40">
            </div>
            <div class="row-2">
              <div class="field">
                <label for="away-short">Sigla</label>
                <input id="away-short" type="text" maxlength="3" style="text-transform:uppercase">
              </div>
              <div class="field">
                <label for="away-color">Color</label>
                <div class="color-row"><input id="away-color" type="color"></div>
              </div>
            </div>
          </div>
        </div>

        <div class="field" style="margin-top:12px">
          <label for="match-title">Título del Partido</label>
          <input id="match-title" type="text">
        </div>
        <div class="field">
          <label for="tournament">Torneo / Fecha</label>
          <input id="tournament" type="text">
        </div>
        <div class="field">
          <label for="stadium">Estadio</label>
          <input id="stadium" type="text">
        </div>
        <div class="field">
          <label for="next-match-title">Título próxima transmisión (modo previa)</label>
          <input id="next-match-title" type="text">
        </div>
        <div class="field">
          <label for="next-match-datetime">Fecha/Hora próximo partido (cuenta regresiva)</label>
          <input id="next-match-datetime" type="datetime-local">
        </div>
      </section>
    </div>

    <!-- TAB 2 -->
    <div class="panel" id="panel-sponsors" role="tabpanel" hidden>
      <section class="card">
        <h2 class="card__title">Auspiciadores Oficiales</h2>
        <p class="card__hint">Tu máquina de monetización. Edita, agrega o quita marcas.</p>
        <div id="sponsors-list"></div>
        <button type="button" class="btn-add" id="add-sponsor">+ Agregar auspiciador</button>
      </section>
    </div>

    <!-- TAB 3 -->
    <div class="panel" id="panel-schedule" role="tabpanel" hidden>
      <section class="card">
        <h2 class="card__title">Próximas Transmisiones</h2>
        <p class="card__hint">Cartelera que aparece en la web pública.</p>
        <div id="upcoming-list"></div>
        <button type="button" class="btn-add" id="add-upcoming">+ Agregar partido a cartelera</button>
      </section>

      <section class="card">
        <h2 class="card__title">Archivo VOD — Revive los Últimos</h2>
        <p class="card__hint">Pega el link de YouTube y el título. La miniatura se genera sola.</p>
        <div id="vod-list"></div>
        <button type="button" class="btn-add" id="add-vod">+ Agregar repetición</button>
      </section>
    </div>
  </div>

  <div class="savebar">
    <button type="button" class="btn-primary" id="btn-save">Guardar y Publicar en Vivo</button>
  </div>
  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <script>
  (() => {
    "use strict";

    const INITIAL = <?= $configJson ?: '{}' ?>;
    const CSRF = <?= $csrfJson ?: '""' ?>;
    const NEXT_LOCAL = <?= json_encode($nextLocal, JSON_UNESCAPED_UNICODE) ?>;

    const state = {
      isLive: true,
      homeScore: 0,
      awayScore: 0,
      youtubeId: "",
      sponsors: [],
      upcoming: [],
      vods: [],
    };

    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];

    function extractYoutubeId(input) {
      const raw = String(input || "").trim();
      if (!raw) return "";
      if (/^[a-zA-Z0-9_-]{11}$/.test(raw)) return raw;
      const patterns = [
        /(?:youtube\.com\/watch\?[^#]*v=|youtu\.be\/|youtube\.com\/live\/|youtube\.com\/embed\/|youtube\.com\/shorts\/|youtube\.com\/v\/|m\.youtube\.com\/watch\?[^#]*v=)([a-zA-Z0-9_-]{11})/,
        /[?&]v=([a-zA-Z0-9_-]{11})/,
        /youtu\.be\/([a-zA-Z0-9_-]{11})/,
      ];
      for (const re of patterns) {
        const m = raw.match(re);
        if (m) return m[1];
      }
      return "";
    }

    function showToast(msg, isError = false) {
      const t = $("#toast");
      t.textContent = msg;
      t.classList.toggle("is-error", isError);
      t.classList.add("is-show");
      clearTimeout(showToast._timer);
      showToast._timer = setTimeout(() => t.classList.remove("is-show"), 3200);
    }

    function updateAirStatus() {
      const pill = $("#air-status");
      const text = $("#air-status-text");
      const label = $("#master-label");
      const sub = $("#master-sub");
      const sw = $("#is-live-switch");
      if (state.isLive) {
        pill.className = "status-pill is-live";
        text.textContent = "AL AIRE";
        label.textContent = "🔴 SEÑAL EN VIVO ACTIVA";
        sub.textContent = "Los hinchas ven el reproductor ahora";
        sw.classList.add("is-on");
        sw.setAttribute("aria-checked", "true");
      } else {
        pill.className = "status-pill is-previa";
        text.textContent = "EN PREVIA";
        label.textContent = "⏳ MODO PREVIA / CUENTA REGRESIVA";
        sub.textContent = "Se muestra el contador hasta el próximo partido";
        sw.classList.remove("is-on");
        sw.setAttribute("aria-checked", "false");
      }
    }

    function setScoreDisplay() {
      $("#home-score").textContent = String(state.homeScore);
      $("#away-score").textContent = String(state.awayScore);
    }

    function syncBadgeChips() {
      const val = $("#match-status-badge").value.trim();
      $$(".badge-chip").forEach((chip) => {
        chip.classList.toggle("is-active", chip.dataset.badge === val);
      });
    }

    function updateYtPreview(fromInput) {
      const id = extractYoutubeId(fromInput);
      state.youtubeId = id;
      const hint = $("#yt-id-hint");
      const preview = $("#yt-preview");
      const thumb = $("#yt-thumb");
      if (id) {
        hint.hidden = false;
        hint.textContent = "ID detectado: " + id;
        thumb.src = "https://i.ytimg.com/vi/" + id + "/hqdefault.jpg";
        preview.classList.add("is-visible");
      } else {
        hint.hidden = true;
        preview.classList.remove("is-visible");
        thumb.removeAttribute("src");
      }
    }

    function esc(str) {
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    /* ----- Sponsors ----- */
    function renderSponsors() {
      const list = $("#sponsors-list");
      list.innerHTML = state.sponsors.map((s, i) => {
        const logo = s.logo_url || "";
        return `
        <div class="item-card" data-idx="${i}">
          <div class="field">
            <label>Marca</label>
            <input type="text" data-f="name" value="${esc(s.name || "")}">
          </div>
          <div class="field">
            <label>Categoría</label>
            <select data-f="category">
              ${["Presentador Oficial","Auspiciador Gold","Auspiciador","Anuncia Aquí"].map((c) =>
                `<option value="${esc(c)}" ${s.category === c ? "selected" : ""}>${esc(c)}</option>`
              ).join("")}
              ${!["Presentador Oficial","Auspiciador Gold","Auspiciador","Anuncia Aquí"].includes(s.category)
                ? `<option value="${esc(s.category)}" selected>${esc(s.category)}</option>` : ""}
            </select>
          </div>
          <div class="field">
            <label>Texto CTA</label>
            <textarea data-f="cta" rows="2">${esc(s.cta || "")}</textarea>
          </div>
          <div class="field">
            <label>Teléfono del auspiciador (se muestra en Contacto)</label>
            <input type="tel" data-f="phone" value="${esc(s.phone || "")}" placeholder="+56912345678" inputmode="tel">
          </div>
          <div class="field">
            <label>WhatsApp del auspiciador (link al tocar Contacto)</label>
            <input type="text" data-f="whatsapp" value="${esc(s.whatsapp || "")}" placeholder="+569... o https://wa.me/...">
          </div>
          <div class="field">
            <label>Tier</label>
            <select data-f="tier">
              <option value="presenter" ${s.tier === "presenter" ? "selected" : ""}>Presentador</option>
              <option value="gold" ${s.tier === "gold" ? "selected" : ""}>Gold</option>
              <option value="available" ${s.tier === "available" ? "selected" : ""}>Espacio disponible</option>
            </select>
          </div>
          <div class="logo-box">
            <label style="font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:8px">Logo / Banner (opcional)</label>
            <input type="hidden" data-f="logo_url" value="${esc(logo)}">
            <img class="logo-box__preview ${logo ? "is-visible" : ""}" data-f="logo-preview" src="${esc(logo)}" alt="Logo auspiciador">
            <div class="logo-box__row">
              <button type="button" class="btn-upload" data-upload-logo="${i}">Subir Logo/Banner desde el celular</button>
              <input type="file" data-f="logo-file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" capture="environment">
              ${logo ? `<button type="button" class="btn-ghost" data-clear-logo="${i}">Quitar imagen</button>` : ""}
            </div>
          </div>
          <div class="item-card__actions">
            <button type="button" class="btn-danger" data-remove-sponsor="${i}">Eliminar</button>
          </div>
        </div>
      `;
      }).join("") || `<p style="color:var(--muted);font-size:.9rem">Sin auspiciadores aún.</p>`;
    }

    function readSponsorsFromDom() {
      state.sponsors = $$("#sponsors-list .item-card").map((card) => ({
        name: card.querySelector('[data-f="name"]').value.trim(),
        category: card.querySelector('[data-f="category"]').value,
        cta: card.querySelector('[data-f="cta"]').value.trim(),
        phone: (card.querySelector('[data-f="phone"]')?.value || "").trim(),
        whatsapp: card.querySelector('[data-f="whatsapp"]').value.trim(),
        tier: card.querySelector('[data-f="tier"]').value,
        logo_url: (card.querySelector('[data-f="logo_url"]')?.value || "").trim(),
      }));
    }

    async function uploadSponsorLogo(index, file) {
      if (!file) return;
      const allowed = ["image/jpeg", "image/png", "image/webp"];
      if (!allowed.includes(file.type)) {
        showToast("Solo JPG, PNG o WEBP", true);
        return;
      }
      const card = $(`#sponsors-list .item-card[data-idx="${index}"]`);
      const btn = card?.querySelector(`[data-upload-logo="${index}"]`);
      if (btn) {
        btn.disabled = true;
        btn.textContent = "Subiendo…";
      }
      try {
        const fd = new FormData();
        fd.append("action", "upload_sponsor_logo");
        fd.append("csrf_token", CSRF);
        fd.append("logo", file);
        const res = await fetch("admin.php?action=upload_sponsor_logo", {
          method: "POST",
          headers: { "X-CSRF-Token": CSRF },
          body: fd,
          credentials: "same-origin",
          cache: "no-store",
        });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || "Error al subir");
        readSponsorsFromDom();
        if (state.sponsors[index]) {
          state.sponsors[index].logo_url = data.path;
        }
        renderSponsors();
        showToast("✅ Logo subido. Recuerda Guardar y Publicar.");
      } catch (err) {
        showToast("❌ " + (err.message || "No se pudo subir"), true);
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.textContent = "Subir Logo/Banner desde el celular";
        }
      }
    }

    /* ----- Upcoming ----- */
    function isoToLocal(iso) {
      if (!iso) return "";
      try {
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return String(iso).slice(0, 16);
        const pad = (n) => String(n).padStart(2, "0");
        // Show Chile-ish wall time from ISO string parts when possible
        const m = String(iso).match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
        if (m) return `${m[1]}T${m[2]}`;
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
      } catch {
        return "";
      }
    }

    function renderUpcoming() {
      const list = $("#upcoming-list");
      list.innerHTML = state.upcoming.map((m, i) => `
        <div class="item-card ${m.featured ? "is-featured" : ""}" data-idx="${i}">
          <div class="row-2">
            <div class="field">
              <label>Local</label>
              <input type="text" data-f="home" value="${esc(m.home || "")}">
            </div>
            <div class="field">
              <label>Visita</label>
              <input type="text" data-f="away" value="${esc(m.away || "")}">
            </div>
          </div>
          <div class="field">
            <label>Fecha y hora</label>
            <input type="datetime-local" data-f="datetime_local" value="${esc(isoToLocal(m.iso) || (m.date && m.time ? m.date + "T" + m.time : ""))}">
          </div>
          <div class="field">
            <label>Torneo</label>
            <input type="text" data-f="tournament" value="${esc(m.tournament || "")}">
          </div>
          <div class="field">
            <label>Estadio</label>
            <input type="text" data-f="stadium" value="${esc(m.stadium || "")}">
          </div>
          <input type="hidden" data-f="featured" value="${m.featured ? "1" : "0"}">
          <div class="item-card__actions">
            <button type="button" class="btn-featured ${m.featured ? "is-on" : ""}" data-feature-upcoming="${i}" aria-pressed="${m.featured ? "true" : "false"}">
              ${m.featured ? "★ Más esperado" : "☆ Marcar como más esperado"}
            </button>
            <button type="button" class="btn-danger" data-remove-upcoming="${i}">Eliminar</button>
          </div>
        </div>
      `).join("") || `<p style="color:var(--muted);font-size:.9rem">Cartelera vacía.</p>`;
    }

    function readUpcomingFromDom() {
      state.upcoming = $$("#upcoming-list .item-card").map((card) => {
        const datetime_local = card.querySelector('[data-f="datetime_local"]').value;
        let date = "", time = "";
        if (datetime_local) {
          const [d, t] = datetime_local.split("T");
          date = d || "";
          time = (t || "").slice(0, 5);
        }
        return {
          home: card.querySelector('[data-f="home"]').value.trim(),
          away: card.querySelector('[data-f="away"]').value.trim(),
          tournament: card.querySelector('[data-f="tournament"]').value.trim(),
          stadium: card.querySelector('[data-f="stadium"]').value.trim(),
          datetime_local,
          date,
          time,
          iso: datetime_local ? datetime_local + ":00-03:00" : "",
          featured: (card.querySelector('[data-f="featured"]')?.value || "0") === "1",
        };
      });
    }

    /* ----- VOD ----- */
    function renderVods() {
      const list = $("#vod-list");
      list.innerHTML = state.vods.map((v, i) => {
        const url = v.youtube_id ? "https://youtu.be/" + v.youtube_id : (v.youtube_url || "");
        const thumb = v.thumbnail || (v.youtube_id ? "https://i.ytimg.com/vi/" + v.youtube_id + "/hqdefault.jpg" : "");
        return `
          <div class="item-card" data-idx="${i}">
            <div class="field">
              <label>Título del partido</label>
              <input type="text" data-f="title" value="${esc(v.title || "")}">
            </div>
            <div class="field">
              <label>Link de YouTube</label>
              <input type="text" data-f="youtube_url" value="${esc(url)}" placeholder="Pega el link completo de YouTube" inputmode="url" autocomplete="off">
            </div>
            <div class="row-2">
              <div class="field">
                <label>Fecha</label>
                <input type="date" data-f="date" value="${esc(v.date || "")}">
              </div>
              <div class="field">
                <label>Torneo</label>
                <input type="text" data-f="tournament" value="${esc(v.tournament || "")}">
              </div>
            </div>
            <img class="vod-thumb ${thumb ? "is-visible" : ""}" data-f="thumb-preview" src="${esc(thumb)}" alt="">
            <div class="item-card__actions">
              <button type="button" class="btn-danger" data-remove-vod="${i}">Eliminar</button>
            </div>
          </div>
        `;
      }).join("") || `<p style="color:var(--muted);font-size:.9rem">Sin repeticiones aún.</p>`;
    }

    function readVodsFromDom() {
      state.vods = $$("#vod-list .item-card").map((card) => {
        const youtube_url = card.querySelector('[data-f="youtube_url"]').value.trim();
        const id = extractYoutubeId(youtube_url);
        return {
          title: card.querySelector('[data-f="title"]').value.trim(),
          youtube_url,
          youtube_id: id,
          date: card.querySelector('[data-f="date"]').value,
          tournament: card.querySelector('[data-f="tournament"]').value.trim(),
          thumbnail: id ? "https://i.ytimg.com/vi/" + id + "/hqdefault.jpg" : "",
        };
      });
    }

    /* ----- Hydrate from config ----- */
    function hydrate(cfg) {
      const ls = cfg.live_stream || {};
      const home = ls.home_team || {};
      const away = ls.away_team || {};

      state.isLive = Boolean(ls.is_live);
      state.homeScore = Number(home.score) || 0;
      state.awayScore = Number(away.score) || 0;
      state.youtubeId = ls.youtube_id || "";
      state.sponsors = Array.isArray(cfg.sponsors) ? cfg.sponsors.map((s) => ({ ...s })) : [];
      state.upcoming = Array.isArray(cfg.upcoming_matches) ? cfg.upcoming_matches.map((m) => ({ ...m })) : [];
      state.vods = Array.isArray(cfg.vod_matches) ? cfg.vod_matches.map((v) => ({ ...v })) : [];

      $("#youtube-url").value = state.youtubeId ? "https://youtu.be/" + state.youtubeId : "";
      updateYtPreview($("#youtube-url").value);

      $("#match-status-badge").value = ls.match_status_badge || "";
      syncBadgeChips();

      $("#home-name").value = home.name || "";
      $("#home-short").value = home.short || "";
      $("#home-color").value = home.color || "#00843D";
      $("#away-name").value = away.name || "";
      $("#away-short").value = away.short || "";
      $("#away-color").value = away.color || "#0038A8";

      $("#match-title").value = ls.match_title || "";
      $("#tournament").value = ls.tournament || "";
      $("#stadium").value = ls.stadium || "";
      $("#next-match-title").value = ls.next_match_title || "";
      $("#next-match-datetime").value = NEXT_LOCAL || isoToLocal(ls.next_match_iso || "");

      setScoreDisplay();
      updateAirStatus();
      renderSponsors();
      renderUpcoming();
      renderVods();
    }

    function collectPayload() {
      readSponsorsFromDom();
      readUpcomingFromDom();
      readVodsFromDom();

      const ytInput = $("#youtube-url").value.trim();
      const ytId = extractYoutubeId(ytInput) || state.youtubeId;

      return {
        live_stream: {
          is_live: state.isLive,
          youtube_url: ytInput,
          youtube_id: ytId,
          match_title: $("#match-title").value.trim(),
          tournament: $("#tournament").value.trim(),
          stadium: $("#stadium").value.trim(),
          match_status_badge: $("#match-status-badge").value.trim(),
          next_match_title: $("#next-match-title").value.trim(),
          next_match_datetime: $("#next-match-datetime").value,
          home_team: {
            name: $("#home-name").value.trim(),
            short: $("#home-short").value.trim().toUpperCase(),
            color: $("#home-color").value,
            score: state.homeScore,
          },
          away_team: {
            name: $("#away-name").value.trim(),
            short: $("#away-short").value.trim().toUpperCase(),
            color: $("#away-color").value,
            score: state.awayScore,
          },
        },
        sponsors: state.sponsors,
        upcoming_matches: state.upcoming,
        vod_matches: state.vods,
      };
    }

    async function saveAll() {
      const btn = $("#btn-save");
      btn.disabled = true;
      btn.textContent = "Publicando…";
      try {
        const payload = collectPayload();
        if (!payload.live_stream.youtube_id && payload.live_stream.is_live) {
          showToast("Pega un link de YouTube válido antes de ir al aire", true);
          btn.disabled = false;
          btn.textContent = "Guardar y Publicar en Vivo";
          return;
        }
        const badVod = (payload.vod_matches || []).find(
          (v) => v.title && !v.youtube_id && String(v.youtube_url || "").trim()
        );
        if (badVod) {
          showToast("Hay un link de YouTube inválido en Archivo VOD: " + badVod.title, true);
          btn.disabled = false;
          btn.textContent = "Guardar y Publicar en Vivo";
          return;
        }
        const res = await fetch("admin.php?action=save", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-Token": CSRF,
          },
          body: JSON.stringify(payload),
          credentials: "same-origin",
          cache: "no-store",
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || "Error al guardar");
        }
        showToast("✅ ¡Señal actualizada en vivo en mdtvmas.cl!");
        if (data.config) {
          state.youtubeId = data.config.live_stream?.youtube_id || state.youtubeId;
          // Rehidratar VOD/cartelera para confirmar lo que realmente quedó guardado
          if (Array.isArray(data.config.vod_matches)) {
            state.vods = data.config.vod_matches.map((v) => ({ ...v }));
            renderVods();
          }
          if (Array.isArray(data.config.upcoming_matches)) {
            state.upcoming = data.config.upcoming_matches.map((m) => ({ ...m }));
            renderUpcoming();
          }
        }
      } catch (err) {
        showToast("❌ " + (err.message || "No se pudo guardar"), true);
      } finally {
        btn.disabled = false;
        btn.textContent = "Guardar y Publicar en Vivo";
      }
    }

    /* ----- Events ----- */
    function bind() {
      // Tabs
      $$(".tabs__btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          const tab = btn.dataset.tab;
          $$(".tabs__btn").forEach((b) => {
            const on = b === btn;
            b.classList.toggle("is-active", on);
            b.setAttribute("aria-selected", String(on));
          });
          $("#panel-live").hidden = tab !== "live";
          $("#panel-sponsors").hidden = tab !== "sponsors";
          $("#panel-schedule").hidden = tab !== "schedule";
        });
      });

      $("#is-live-switch").addEventListener("click", () => {
        state.isLive = !state.isLive;
        updateAirStatus();
      });

      $("#youtube-url").addEventListener("input", (e) => updateYtPreview(e.target.value));
      $("#youtube-url").addEventListener("paste", (e) => {
        setTimeout(() => updateYtPreview(e.target.value), 0);
      });

      $$(".badge-chip").forEach((chip) => {
        chip.addEventListener("click", () => {
          $("#match-status-badge").value = chip.dataset.badge;
          syncBadgeChips();
        });
      });
      $("#match-status-badge").addEventListener("input", syncBadgeChips);

      $$(".score-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          const side = btn.dataset.score;
          const delta = Number(btn.dataset.delta);
          if (side === "home") {
            state.homeScore = Math.max(0, state.homeScore + delta);
          } else {
            state.awayScore = Math.max(0, state.awayScore + delta);
          }
          setScoreDisplay();
        });
      });

      $("#add-sponsor").addEventListener("click", () => {
        readSponsorsFromDom();
        state.sponsors.push({
          name: "Nueva Marca",
          category: "Auspiciador Gold",
          cta: "Tu mensaje aquí",
          phone: "",
          whatsapp: "",
          tier: "gold",
          logo_url: "",
        });
        renderSponsors();
      });

      $("#sponsors-list").addEventListener("click", (e) => {
        const removeBtn = e.target.closest("[data-remove-sponsor]");
        if (removeBtn) {
          readSponsorsFromDom();
          state.sponsors.splice(Number(removeBtn.dataset.removeSponsor), 1);
          renderSponsors();
          return;
        }

        const clearBtn = e.target.closest("[data-clear-logo]");
        if (clearBtn) {
          readSponsorsFromDom();
          const idx = Number(clearBtn.dataset.clearLogo);
          if (state.sponsors[idx]) state.sponsors[idx].logo_url = "";
          renderSponsors();
          return;
        }

        const uploadBtn = e.target.closest("[data-upload-logo]");
        if (uploadBtn) {
          const idx = Number(uploadBtn.dataset.uploadLogo);
          const card = uploadBtn.closest(".item-card");
          const fileInput = card?.querySelector('[data-f="logo-file"]');
          if (!fileInput) return;
          fileInput.onchange = () => {
            const file = fileInput.files && fileInput.files[0];
            uploadSponsorLogo(idx, file);
            fileInput.value = "";
          };
          fileInput.click();
        }
      });

      $("#add-upcoming").addEventListener("click", () => {
        readUpcomingFromDom();
        state.upcoming.push({
          home: "Equipo Local",
          away: "Equipo Visita",
          tournament: "Tercera División B - Grupo Norte",
          stadium: "Estadio",
          date: "",
          time: "16:00",
          iso: "",
          featured: false,
        });
        renderUpcoming();
      });

      $("#upcoming-list").addEventListener("click", (e) => {
        const featureBtn = e.target.closest("[data-feature-upcoming]");
        if (featureBtn) {
          readUpcomingFromDom();
          const idx = Number(featureBtn.dataset.featureUpcoming);
          const turningOn = !state.upcoming[idx]?.featured;
          state.upcoming.forEach((m, i) => {
            m.featured = turningOn && i === idx;
          });
          renderUpcoming();
          return;
        }
        const btn = e.target.closest("[data-remove-upcoming]");
        if (!btn) return;
        readUpcomingFromDom();
        state.upcoming.splice(Number(btn.dataset.removeUpcoming), 1);
        renderUpcoming();
      });

      $("#add-vod").addEventListener("click", () => {
        readVodsFromDom();
        const today = new Date().toISOString().slice(0, 10);
        state.vods.unshift({
          title: "Nuevo partido — resultado",
          date: today,
          tournament: "Tercera División B",
          youtube_id: "",
          youtube_url: "",
          thumbnail: "",
        });
        renderVods();
      });

      $("#vod-list").addEventListener("click", (e) => {
        const btn = e.target.closest("[data-remove-vod]");
        if (!btn) return;
        readVodsFromDom();
        state.vods.splice(Number(btn.dataset.removeVod), 1);
        renderVods();
      });

      $("#vod-list").addEventListener("input", (e) => {
        const input = e.target.closest('[data-f="youtube_url"]');
        if (!input) return;
        const card = input.closest(".item-card");
        const id = extractYoutubeId(input.value);
        const img = card.querySelector('[data-f="thumb-preview"]');
        if (id && img) {
          img.src = "https://i.ytimg.com/vi/" + id + "/hqdefault.jpg";
          img.classList.add("is-visible");
        }
      });

      $("#btn-save").addEventListener("click", saveAll);
    }

    hydrate(INITIAL);
    bind();
  })();
  </script>
<?php endif; ?>
</body>
</html>
