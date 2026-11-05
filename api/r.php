<?php
// citas.odlc.es/r/:id — entrega la app con los metadatos Open Graph en el HTML (WhatsApp no ejecuta JavaScript),
// y sirve /r/:id/calendario.ics y /r/:id/material/:idArchivo. Solo lectura.
declare(strict_types=1);
require __DIR__ . '/_lib/core.php';
require __DIR__ . '/_lib/meeting.php';

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); exit; }
$id = (string)($_GET['id'] ?? ''); $x = (string)($_GET['x'] ?? '');
$m = preg_match(ID_RE, $id) ? get_json("m/$id") : null;

if ($m && $x === 'calendario.ics') {
    base_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="reunion-odlc.ics"');
    echo build_ics($m); exit;
}
if ($m && preg_match('#^material/([A-Za-z0-9_-]{16})$#', $x, $f)) {
    $item = null; foreach ($m['materials'] ?? [] as $it) if ($it['id'] === $f[1] && $it['kind'] === 'file') $item = $it;
    $p = $item ? file_path($m['id'], $item['id']) : '';
    if ($item && estado($m) !== 'cancelada' && is_file($p)) {
        base_headers();
        header('Content-Type: ' . $item['type']);
        header('Content-Length: ' . filesize($p));
        header("Content-Disposition: attachment; filename=\"" . preg_replace('/[^\x20-\x7e]|"/', '_', $item['name']) . "\"; filename*=UTF-8''" . rawurlencode($item['name']));
        header("Content-Security-Policy: sandbox; default-src 'none'");
        readfile($p); exit;
    }
    $m = null;
}

$html = (string)file_get_contents(dirname(__DIR__) . '/index.html');
$site = site_url();
$og = '<title>Invitación a reunión · OdlC</title>
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="description" content="Consulte la invitación, únase por Microsoft Teams y añádala a su calendario.">
<meta property="og:type" content="website"><meta property="og:locale" content="es_ES"><meta property="og:site_name" content="OdlC">
<meta property="og:title" content="Invitación a reunión · OdlC">
<meta property="og:description" content="Consulte la invitación, únase por Microsoft Teams y añádala a su calendario.">
<meta property="og:url" content="' . esc($site . '/r/' . $id) . '">
<meta property="og:image" content="https://odlc.es/assets/img/og/invitacion-odlc.png"><meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="OdlC · Invitación a reunión">
<meta name="twitter:card" content="summary_large_image">';
$html = preg_replace('#<title>.*?</title>#s', $og, $html, 1);
base_headers();
http_response_code($m ? 200 : 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
echo $html;
