<?php
// Reuniones: validación, hora de Madrid (verano/invierno), textos, .ics, calendarios, WhatsApp, materiales.
declare(strict_types=1);

const ID_RE = '/^[A-Za-z0-9_-]{22}$/';
function new_id(): string { return b64u(random_bytes(16)); }

function tz(): DateTimeZone { static $z; return $z ??= new DateTimeZone(ODLC_TZ); }
function dt(int $ms): DateTimeImmutable { return (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(tz()); }

// Hora local de Madrid -> milisegundos UTC; null si esa hora no existe (salto de marzo)
function madrid_to_utc(string $date, string $time): ?int {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", tz());
    if (!$d || $d->format('Y-m-d H:i') !== "$date $time") return null;
    return $d->getTimestamp() * 1000;
}
function local_parts(int $ms): array { $d = dt($ms); return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i')]; }
function fmt(int $ms, string $pattern): string {
    $f = new IntlDateFormatter('es_ES', IntlDateFormatter::NONE, IntlDateFormatter::NONE, ODLC_TZ, IntlDateFormatter::GREGORIAN, $pattern);
    return (string)$f->format(intdiv($ms, 1000));
}
function ucfirst_u(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
function fecha_larga(int $ms): string { return ucfirst_u(fmt($ms, "EEEE, d 'de' MMMM 'de' y")); }
function fecha_corta(int $ms): string { return str_replace('.', '', fmt($ms, 'd MMM y')); }
function dia_semana(int $ms): string { return ucfirst_u(fmt($ms, 'EEEE')); }
function hora_corta(int $ms): string { return dt($ms)->format('H:i'); }
function utc_offset(int $ms): string { return 'UTC+' . (dt($ms)->getOffset() / 3600); }

function is_teams_url(string $u): bool {
    $p = parse_url($u);
    return $p && ($p['scheme'] ?? '') === 'https' && preg_match('/(^|\.)(teams\.microsoft\.com|teams\.live\.com|teams\.cloud\.microsoft)$/i', $p['host'] ?? '') && strlen($p['path'] ?? '') > 1;
}
function clean_str($v, int $max): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)($v ?? '')) ?? '';
    return mb_substr(trim($s), 0, $max);
}

const MAX_GUESTS = 10;
function join_names(array $n): string { return count($n) < 2 ? ($n[0] ?? '') : implode(', ', array_slice($n, 0, -1)) . ' y ' . end($n); }
// Invitados de una reunión (las reuniones antiguas solo tienen los campos sueltos)
function guests_of(array $m): array {
    if (!empty($m['guests']) && is_array($m['guests'])) return $m['guests'];
    if (($m['guestName'] ?? '') === '' && ($m['guestEmail'] ?? '') === '' && ($m['guestPhone'] ?? '') === '') return [];
    return [['name' => $m['guestName'] ?? '', 'email' => $m['guestEmail'] ?? '', 'phone' => $m['guestPhone'] ?? '']];
}

function validate_input(array $b, bool $requireTeams, bool $allowPast = false): array {
    $e = [];
    $team = array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/', clean_str($b['team'] ?? '', 400)) ?: [])));
    // Invitados: lista de { name, email, phone }; se aceptan también los campos sueltos de versiones anteriores
    $raw = is_array($b['guests'] ?? null) ? $b['guests'] : [['name' => $b['guestName'] ?? '', 'email' => $b['guestEmail'] ?? '', 'phone' => $b['guestPhone'] ?? '']];
    $guests = [];
    foreach (array_slice($raw, 0, MAX_GUESTS) as $i => $g) {
        if (!is_array($g)) continue;
        $g = ['name' => clean_str($g['name'] ?? '', 80), 'email' => mb_strtolower(clean_str($g['email'] ?? '', 160)), 'phone' => clean_str($g['phone'] ?? '', 30)];
        if ($g['name'] === '' && $g['email'] === '' && $g['phone'] === '') continue;
        $n = count($guests);
        if ($g['email'] !== '' && !filter_var($g['email'], FILTER_VALIDATE_EMAIL)) $e["guests.$n.email"] = 'El correo no es válido.';
        if ($g['phone'] !== '' && !preg_match('/^\+?\d{9,15}$/', preg_replace('/[^\d+]/', '', $g['phone']))) $e["guests.$n.phone"] = 'Escribe el móvil con prefijo, por ejemplo +34 600 000 000.';
        $guests[] = $g;
    }
    $first = fn(string $k) => array_values(array_filter(array_column($guests, $k)))[0] ?? '';
    $d = [
        'guests' => $guests,
        'guestName' => mb_substr(join_names(array_values(array_filter(array_column($guests, 'name')))), 0, 240),
        'guestEmail' => $first('email'),
        'guestPhone' => $first('phone'),
        'subject' => clean_str($b['subject'] ?? '', 160),
        'date' => clean_str($b['date'] ?? '', 10),
        'time' => clean_str($b['time'] ?? '', 5),
        'duration' => (int)($b['duration'] ?? 0),
        'description' => clean_str($b['description'] ?? '', 2000),
        'teamsUrl' => clean_str($b['teamsUrl'] ?? '', 2000),
        'notes' => clean_str($b['notes'] ?? '', 2000),
        'team' => $team ? array_slice($team, 0, 6) : [ODLC_NOMBRE],
    ];
    if ($d['subject'] === '') $e['subject'] = 'Escribe el asunto de la reunión.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'])) $e['date'] = 'Elige una fecha.';
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $d['time'])) $e['time'] = 'Elige una hora válida.';
    if (!in_array($d['duration'], DURACIONES, true)) $e['duration'] = 'Elige una duración: 15, 30, 45, 60 o 90 minutos.';
    if (!isset($e['date']) && !isset($e['time'])) {
        $s = madrid_to_utc($d['date'], $d['time']);
        $now = time() * 1000;
        if ($s === null) $e['time'] = 'Esa hora no existe en Madrid (cambio al horario de verano). Elige otra.';
        elseif (!$allowPast && $s < $now - 300000) $e['date'] = 'La fecha y la hora ya han pasado.';
        elseif ($s > $now + 2 * 365 * 86400000) $e['date'] = 'La fecha está demasiado lejos.';
        else { $d['startUtc'] = $s; $d['endUtc'] = $s + $d['duration'] * 60000; }
    }
    if ($d['teamsUrl'] !== '' && !is_teams_url($d['teamsUrl'])) $e['teamsUrl'] = 'Pega un enlace de Microsoft Teams que empiece por https://teams.microsoft.com/…';
    if ($requireTeams && $d['teamsUrl'] === '') $e['teamsUrl'] = 'Pega el enlace de la reunión de Teams.';
    return $e ? ['errors' => $e] : ['data' => $d];
}

function estado(array $m): string {
    if (($m['status'] ?? '') === 'cancelada') return 'cancelada';
    return $m['endUtc'] < time() * 1000 ? 'pasada' : 'proxima';
}
function team_of(array $m): array { return !empty($m['team']) ? $m['team'] : [ODLC_NOMBRE]; }
function invite_url(array $m): string { return site_url() . '/r/' . $m['id']; }
function ics_url(array $m): string { return invite_url($m) . '/calendario.ics'; }
function material_url(array $m, array $f): string { return invite_url($m) . '/material/' . $f['id']; }
function card_url(): string { return ODLC_WEB . '/vcard_ioc'; }

function calendar_body(array $m): string {
    $l = [];
    if (!empty($m['description'])) $l[] = $m['description'];
    if ($l) $l[] = '';
    $l[] = 'Por OdlC: ' . implode(', ', team_of($m));
    if (!empty($m['guestName'])) $l[] = 'Invitado: ' . $m['guestName'];
    $l[] = '';
    $l[] = 'Reunión online por Microsoft Teams.';
    if (!empty($m['teamsUrl'])) $l[] = 'Unirse a la reunión: ' . $m['teamsUrl'];
    array_push($l, '', 'Invitación y materiales: ' . invite_url($m), 'Tarjeta de contacto: ' . card_url(), '', ODLC_NOMBRE . ' · ' . ODLC_EMPRESA, ODLC_WEB);
    return implode("\n", $l);
}

function calendar_links(array $m): array {
    $z = fn(int $ms) => gmdate('Ymd\THis\Z', intdiv($ms, 1000));
    $iso = fn(int $ms) => gmdate('Y-m-d\TH:i:s\Z', intdiv($ms, 1000));
    $body = calendar_body($m);
    $outlook = fn(string $base) => $base . '?' . http_build_query(['path' => '/calendar/action/compose', 'rru' => 'addevent', 'subject' => $m['subject'], 'startdt' => $iso($m['startUtc']), 'enddt' => $iso($m['endUtc']), 'body' => $body, 'location' => 'Microsoft Teams'], '', '&', PHP_QUERY_RFC3986);
    return [
        'google' => 'https://calendar.google.com/calendar/render?' . http_build_query(['action' => 'TEMPLATE', 'text' => $m['subject'], 'dates' => $z($m['startUtc']) . '/' . $z($m['endUtc']), 'details' => $body, 'location' => $m['teamsUrl'] ?: 'Microsoft Teams', 'ctz' => ODLC_TZ], '', '&', PHP_QUERY_RFC3986),
        'outlookWork' => $outlook('https://outlook.office.com/calendar/0/deeplink/compose'),
        'outlookPersonal' => $outlook('https://outlook.live.com/calendar/0/deeplink/compose'),
        'ics' => ics_url($m),
    ];
}

// ---------- .ics (RFC 5545) ----------
function ics_esc(string $s): string { return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s); }
function ics_fold(string $line): string {
    $out = []; $cur = ''; $bytes = 0;
    foreach (mb_str_split($line) as $ch) {
        $b = strlen($ch);
        if ($bytes + $b > ($out ? 74 : 75)) { $out[] = $cur; $cur = ''; $bytes = 0; }
        $cur .= $ch; $bytes += $b;
    }
    $out[] = $cur;
    return implode("\r\n ", $out);
}
function build_ics(array $m): string {
    $cancel = ($m['status'] ?? '') === 'cancelada';
    $loc = fn(int $ms) => dt($ms)->format('Ymd\THis');
    $utc = fn(int $ms) => gmdate('Ymd\THis\Z', intdiv($ms, 1000));
    $l = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//OdlC//Reuniones OdlC//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'BEGIN:VTIMEZONE', 'TZID:Europe/Madrid', 'X-LIC-LOCATION:Europe/Madrid',
        'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
        'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
        'END:VTIMEZONE', 'BEGIN:VEVENT',
        'UID:' . $m['uid'], 'DTSTAMP:' . $utc(time() * 1000), 'LAST-MODIFIED:' . $utc($m['updatedAt'] ?? $m['createdAt']), 'CREATED:' . $utc($m['createdAt']),
        'SEQUENCE:' . (int)($m['sequence'] ?? 0),
        'DTSTART;TZID=Europe/Madrid:' . $loc($m['startUtc']), 'DTEND;TZID=Europe/Madrid:' . $loc($m['endUtc']),
        'SUMMARY:' . ics_esc(($cancel ? 'Cancelada: ' : '') . $m['subject']),
        'DESCRIPTION:' . ics_esc(calendar_body($m)), 'LOCATION:Microsoft Teams',
        'ORGANIZER;CN=' . ics_esc(ODLC_NOMBRE) . ':mailto:' . ODLC_EMAIL,
        'STATUS:' . ($cancel ? 'CANCELLED' : 'CONFIRMED'), 'TRANSP:OPAQUE'];
    if (!empty($m['teamsUrl'])) { $l[] = 'URL:' . $m['teamsUrl']; $l[] = 'X-MICROSOFT-SKYPETEAMSMEETINGURL:' . $m['teamsUrl']; }
    if (!$cancel) array_push($l, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_esc($m['subject']), 'TRIGGER:-PT15M', 'END:VALARM');
    array_push($l, 'END:VEVENT', 'END:VCALENDAR');
    return implode("\r\n", array_map('ics_fold', $l)) . "\r\n";
}

// ---------- WhatsApp ----------
function clock_emoji(string $hhmm): string {
    [$h, $mi] = array_map('intval', explode(':', $hhmm));
    $full = ['🕛', '🕐', '🕑', '🕒', '🕓', '🕔', '🕕', '🕖', '🕗', '🕘', '🕙', '🕚'];
    $half = ['🕧', '🕜', '🕝', '🕞', '🕟', '🕠', '🕡', '🕢', '🕣', '🕤', '🕥', '🕦'];
    return ($mi >= 15 && $mi < 45 ? $half : $full)[($mi >= 45 ? $h + 1 : $h) % 12];
}
function whatsapp_text(array $m, ?string $name = null): string {
    $h = hora_corta($m['startUtc']); $name = $name ?? ($m['guestName'] ?? '');
    return implode("\n", [
        $name !== '' ? "Hola, $name:" : 'Hola:', '',
        'Te envío la invitación para nuestra reunión con OdlC.', '',
        '📅 ' . fecha_larga($m['startUtc']), clock_emoji($h) . " $h h (Madrid)", '💻 Microsoft Teams', '',
        'Puedes consultar la invitación, unirte a la reunión y añadirla a tu calendario aquí:', '',
        invite_url($m), '', 'Un saludo,', '', ODLC_NOMBRE, ODLC_EMPRESA,
    ]);
}
function whatsapp_link(array $m, ?string $text = null, ?string $phone = null): string {
    $p = preg_replace('/\D/', '', (string)($phone ?? $m['guestPhone'] ?? ''));
    if (strlen($p) === 9) $p = '34' . $p;
    return "https://wa.me/$p?text=" . rawurlencode($text ?? whatsapp_text($m));
}

// ---------- Materiales ----------
const FILE_TYPES = [
    'application/pdf' => 'PDF',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word', 'application/msword' => 'Word',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel', 'application/vnd.ms-excel' => 'Excel',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PowerPoint', 'application/vnd.ms-powerpoint' => 'PowerPoint',
    'image/png' => 'Imagen', 'image/jpeg' => 'Imagen', 'text/plain' => 'Texto', 'text/csv' => 'CSV',
];
function clean_file_name(string $n): string {
    $n = preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/u', '_', Normalizer::normalize($n) ?: $n) ?? 'documento';
    return mb_substr($n, 0, 120) ?: 'documento';
}
function size_text(int $b): string { return $b < 1048576 ? max(1, (int)round($b / 1024)) . ' KB' : str_replace('.', ',', sprintf('%.1f', $b / 1048576)) . ' MB'; }
function is_onedrive(string $u): bool { return (bool)preg_match('/(sharepoint\.com|onedrive\.live\.com|1drv\.ms)$/i', (string)parse_url($u, PHP_URL_HOST)); }

// Datos públicos de la invitación (lo que ve el invitado y la app React): sin correo, móvil ni notas
function public_view(array $m): array {
    $st = estado($m);
    $mats = $st === 'cancelada' ? [] : array_map(fn($f) => $f['kind'] === 'link'
        ? ['kind' => 'link', 'name' => $f['name'], 'url' => $f['url'], 'label' => is_onedrive($f['url']) ? 'OneDrive' : 'Enlace']
        : ['kind' => 'file', 'name' => $f['name'], 'url' => material_url($m, $f), 'label' => FILE_TYPES[$f['type']] ?? 'Archivo', 'size' => size_text((int)$f['size'])], $m['materials'] ?? []);
    return [
        'id' => $m['id'], 'estado' => $st, 'subject' => $m['subject'], 'guestName' => $m['guestName'] ?? '', 'team' => team_of($m),
        'description' => $m['description'] ?? '', 'duration' => $m['duration'],
        'fecha' => fecha_corta($m['startUtc']), 'dia' => dia_semana($m['startUtc']), 'fechaLarga' => fecha_larga($m['startUtc']),
        'hora' => hora_corta($m['startUtc']), 'horaFin' => hora_corta($m['endUtc']), 'zona' => utc_offset($m['startUtc']),
        'teamsUrl' => $st === 'proxima' ? ($m['teamsUrl'] ?? '') : '', 'calendar' => calendar_links($m), 'materials' => $mats,
    ];
}
// Vista completa para el panel privado
function admin_view(array $m): array {
    return $m + [
        'estado' => estado($m), 'fecha' => fecha_larga($m['startUtc']), 'hora' => hora_corta($m['startUtc']), 'horaFin' => hora_corta($m['endUtc']),
        'local' => local_parts($m['startUtc']), 'url' => invite_url($m), 'whatsappText' => whatsapp_text($m), 'whatsappLink' => whatsapp_link($m), 'guests' => guests_of($m),
    ];
}
