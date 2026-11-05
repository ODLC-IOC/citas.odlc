<?php
// Microsoft 365 (Outlook + Teams) con Microsoft Graph.
// OAuth 2.0 «authorization code» + PKCE, aplicación web confidencial.
// Permisos delegados: openid, offline_access, User.Read, Calendars.ReadWrite.
// El token de actualización se guarda cifrado (libsodium) en _datos; nunca llega al navegador.
declare(strict_types=1);

const SCOPES = 'openid offline_access User.Read Calendars.ReadWrite BookingsAppointment.ReadWrite.All';

class GraphError extends RuntimeException {}

function ms_missing(): array {
    $m = [];
    foreach (['ms_tenant_id', 'ms_client_id', 'ms_client_secret', 'token_key'] as $k) if (cfg($k) === '') $m[] = $k;
    if (cfg('token_key') !== '' && strlen(base64_decode((string)cfg('token_key'))) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) $m[] = 'token_key (32 bytes)';
    return $m;
}
function graph_base(): string { return rtrim((string)cfg('graph_base', 'https://graph.microsoft.com/v1.0'), '/'); }
// Solo el directorio de Microsoft 365 de OdlC (su inquilino): ahí la identidad de cada cuenta la controla OdlC.
// Las personas de fuera entran como invitadas de ese directorio. Además, deben estar en la lista de autorizados.
function authority(): string { return rtrim((string)cfg('login_base', 'https://login.microsoftonline.com'), '/') . '/' . rawurlencode((string)cfg('ms_tenant_id')) . '/oauth2/v2.0'; }
function redirect_uri(): string { return (string)cfg('ms_redirect_uri', site_url() . '/api/ms/callback'); }

function enc(string $t): string { $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($n . sodium_crypto_secretbox($t, $n, base64_decode((string)cfg('token_key')))); }
function dec(string $b): string {
    $raw = base64_decode($b);
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), base64_decode((string)cfg('token_key')));
    if ($p === false) throw new GraphError('No se ha podido leer la conexión con Microsoft 365.');
    return $p;
}

function http_req(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 25]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new GraphError('No se ha podido conectar con Microsoft: ' . $err);
    return [$code, json_decode((string)$res, true) ?? []];
}

function token_request(array $params, string $scope = SCOPES): array {
    [$code, $d] = http_req('POST', authority() . '/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => cfg('ms_client_id'), 'client_secret' => cfg('ms_client_secret'), 'scope' => $scope] + $params));
    if ($code >= 400) { $e = new GraphError($d['error_description'] ?? ($d['error'] ?? "Error $code al obtener el token")); throw $e; }
    return $d;
}

const LOGIN_SCOPES = 'openid profile email';

// purpose: 'login' (iniciar sesión en el panel) o 'calendar' (conectar el calendario de la administradora)
function ms_authorize_url(string $purpose = 'calendar'): string {
    $state = b64u(random_bytes(24));
    $verifier = b64u(random_bytes(48));
    $nonce = b64u(random_bytes(16));
    set_json('ms/state-' . $state, ['verifier' => $verifier, 'nonce' => $nonce, 'purpose' => $purpose, 'exp' => time() + 600]);
    $q = ['client_id' => cfg('ms_client_id'), 'response_type' => 'code', 'redirect_uri' => redirect_uri(), 'response_mode' => 'query',
        'scope' => $purpose === 'login' ? LOGIN_SCOPES : SCOPES, 'state' => $state, 'nonce' => $nonce,
        'code_challenge' => b64u(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'prompt' => 'select_account'];
    if ($purpose === 'calendar') $q['login_hint'] = admin_email();
    return authority() . '/authorize?' . http_build_query($q);
}

// Comprueba el id_token recibido directamente de Microsoft por TLS (OpenID Connect, intercambio de código en servidor)
function id_token_email(string $jwt, string $nonce): string {
    $parts = explode('.', $jwt);
    $c = count($parts) === 3 ? json_decode(b64u_dec($parts[1]), true) : null;
    if (!is_array($c)) throw new GraphError('Respuesta de Microsoft no válida.');
    if (($c['aud'] ?? '') !== cfg('ms_client_id')) throw new GraphError('El token no es para esta aplicación.');
    if (($c['exp'] ?? 0) < time() - 60) throw new GraphError('El inicio de sesión ha caducado.');
    if (!hash_equals($nonce, (string)($c['nonce'] ?? ''))) throw new GraphError('El inicio de sesión no es válido.');
    $tenant = strtolower((string)cfg('ms_tenant_id'));
    if (strtolower((string)($c['tid'] ?? '')) !== $tenant) throw new GraphError('La cuenta no pertenece al directorio de OdlC.');
    if (!cfg('login_base') && strtolower((string)($c['iss'] ?? '')) !== "https://login.microsoftonline.com/$tenant/v2.0") throw new GraphError('Emisor del token no reconocido.');
    // preferred_username = nombre de inicio de sesión en el directorio de OdlC (no se usa el claim «email», que no está verificado)
    return strtolower(trim((string)($c['preferred_username'] ?? '')));
}

// Resultado: ['purpose' => 'login', 'email' => …] o ['purpose' => 'calendar', 'account' => …]
function ms_callback(array $q): array {
    if (!empty($q['error'])) throw new GraphError($q['error_description'] ?? $q['error']);
    $state = (string)($q['state'] ?? ''); $code = (string)($q['code'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{32}$/', $state) || $code === '') throw new GraphError('Respuesta de Microsoft incompleta.');
    $p = get_json('ms/state-' . $state); del_key('ms/state-' . $state);
    if (!$p || $p['exp'] < time()) throw new GraphError('La autorización ha caducado. Vuelve a intentarlo.');
    if (($p['purpose'] ?? '') === 'login') {
        $tok = token_request(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => redirect_uri(), 'code_verifier' => $p['verifier']], LOGIN_SCOPES);
        $email = id_token_email((string)($tok['id_token'] ?? ''), $p['nonce']);
        if (!isset(users()[$email])) throw new GraphError("La cuenta $email no está autorizada. Pide a " . ODLC_NOMBRE . ' que te dé acceso.');
        return ['purpose' => 'login', 'email' => $email];
    }
    $tok = token_request(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => redirect_uri(), 'code_verifier' => $p['verifier']]);
    $me = graph('GET', '/me?$select=displayName,mail,userPrincipalName', null, $tok['access_token']);
    $acc = strtolower((string)($me['mail'] ?? $me['userPrincipalName'] ?? ''));
    if ($acc !== admin_email()) throw new GraphError("La cuenta $acc no está autorizada. Entra con " . admin_email() . '.');
    $cal = graph('GET', '/me/calendar?$select=allowedOnlineMeetingProviders', null, $tok['access_token']);
    $prov = $cal['allowedOnlineMeetingProviders'] ?? [];
    set_json('ms/token', ['refresh' => enc($tok['refresh_token']), 'account' => $acc, 'providers' => $prov, 'teams' => in_array('teamsForBusiness', $prov, true), 'connectedAt' => time() * 1000]);
    return ['purpose' => 'calendar', 'account' => $acc];
}

function ms_status(): array {
    if ($m = ms_missing()) return ['configured' => false, 'missing' => $m];
    $t = get_json('ms/token');
    if (!$t) return ['configured' => true, 'connected' => false];
    return ['configured' => true, 'connected' => true, 'account' => $t['account'], 'teams' => $t['teams'], 'providers' => $t['providers']];
}
// Algunas cuentas no informan de Teams en el calendario aunque lo tengan: basta con estar conectada.
// Si Microsoft no devuelve el enlace al crear la reunión, se avisa y se borra el evento.
// Vuelve a mirar si el calendario ya admite Teams (p. ej. tras comprar Teams Essentials), como mucho cada 10 minutos
function ms_recheck_teams(): void {
    $t = get_json('ms/token');
    if (!$t || !empty($t['teams']) || (($t['checkedAt'] ?? 0) > time() - 600)) return;
    try { $cal = graph('GET', '/me/calendar?$select=allowedOnlineMeetingProviders'); $prov = $cal['allowedOnlineMeetingProviders'] ?? []; }
    catch (Throwable $e) { return; }
    $t = get_json('ms/token') ?? $t;
    $t['providers'] = $prov; $t['teams'] = in_array('teamsForBusiness', $prov, true); $t['checkedAt'] = time();
    set_json('ms/token', $t);
}
function ms_ready(): bool { $s = ms_status(); return !empty($s['connected']); }

function access_token(): string {
    static $cached = null;
    if ($cached) return $cached;
    if (ms_missing()) throw new GraphError('La integración con Microsoft 365 no está configurada.');
    $t = get_json('ms/token');
    if (!$t) throw new GraphError('Microsoft 365 no está conectado. Conéctalo desde el panel.');
    try { $tok = token_request(['grant_type' => 'refresh_token', 'refresh_token' => dec($t['refresh'])]); }
    catch (GraphError $e) { del_key('ms/token'); throw new GraphError('La conexión con Microsoft 365 ha caducado. Vuelve a conectarla desde el panel.'); }
    if (!empty($tok['refresh_token'])) { $t['refresh'] = enc($tok['refresh_token']); set_json('ms/token', $t); }
    return $cached = $tok['access_token'];
}

function graph(string $method, string $path, ?array $body = null, ?string $token = null, string $tzPref = ODLC_TZ): array {
    [$code, $d] = http_req($method, graph_base() . $path, [
        'Authorization: Bearer ' . ($token ?? access_token()), 'Content-Type: application/json', 'Prefer: outlook.timezone="' . $tzPref . '"',
    ], $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($code >= 400) { $e = new GraphError(trim((string)($d['error']['message'] ?? '')) ?: "Microsoft Graph respondió $code" . (!empty($d['error']['code']) ? ' (' . $d['error']['code'] . ')' : '')); throw $e; }
    return $d;
}
function graph_code(string $method, string $path, ?array $body = null): int {
    [$code] = http_req($method, graph_base() . $path, ['Authorization: Bearer ' . access_token(), 'Content-Type: application/json'], $body === null ? null : json_encode($body));
    return $code;
}

// ---------- Eventos ----------
function local_iso(int $ms): string { return dt($ms)->format('Y-m-d\TH:i:s'); }
function links_html(array $m): string {
    // La imagen de la invitación (enlazada) y debajo el enlace, como en la vista previa de WhatsApp
    return '<p><a href="' . esc(invite_url($m)) . '"><img src="' . esc(invite_url($m) . '/vista-' . (int)($m['sequence'] ?? 0) . '.jpg') . '" width="480" alt="OdlC · Invitación a reunión" style="display:block;width:480px;max-width:100%;height:auto;border:0;border-radius:8px"></a></p>'
        . '<p style="font-family:Arial,sans-serif;font-size:14px;color:#17302A"><a href="' . esc(invite_url($m)) . '">' . esc(invite_url($m)) . '</a><br>'
        . 'Invitación, materiales y calendario · <a href="' . esc(card_url()) . '">Tarjeta de contacto de ' . esc(ODLC_NOMBRE) . '</a></p>'
        . (($m['mode'] ?? '') !== 'auto' && !empty($m['teamsUrl']) ? '<p style="font-family:Arial,sans-serif;font-size:15px"><b><a href="' . esc($m['teamsUrl']) . '">Unirse a la reunión de Microsoft Teams</a></b></p>' : '') . '<hr>';
}
function event_payload(array $m, bool $invite): array {
    $e = [
        'subject' => $m['subject'],
        'body' => ['contentType' => 'HTML', 'content' => event_html($m)],
        'start' => ['dateTime' => local_iso($m['startUtc']), 'timeZone' => ODLC_TZ],
        'end' => ['dateTime' => local_iso($m['endUtc']), 'timeZone' => ODLC_TZ],
    ];
    if ($invite) {
        $att = [];
        foreach (guests_of($m) as $g) if ($g['email'] !== '') $att[] = ['emailAddress' => ['address' => $g['email'], 'name' => $g['name'] ?: $g['email']], 'type' => 'required'];
        if ($att) $e['attendees'] = $att;
    }
    return $e;
}
function create_teams_event(array $m, bool $invite): array {
    $ev = graph('POST', '/me/events', event_payload($m, $invite) + ['isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness', 'transactionId' => $m['id']]);
    $join = $ev['onlineMeeting']['joinUrl'] ?? '';
    for ($i = 0; !$join && $i < 4; $i++) { usleep(1200000); $a = graph('GET', '/me/events/' . rawurlencode($ev['id']) . '?$select=onlineMeeting'); $join = $a['onlineMeeting']['joinUrl'] ?? ''; }
    if (!$join) { graph_code('DELETE', '/me/events/' . rawurlencode($ev['id'])); throw new GraphError('Microsoft no ha devuelto el enlace de Teams (no se ha dejado ningún evento en tu calendario). Revisa que tu cuenta tiene Teams o usa el modo manual.'); }
    return ['eventId' => $ev['id'], 'joinUrl' => $join];
}
// Evento normal de Outlook con el enlace de Teams pegado a mano (cuentas sin Teams de empresa, p. ej. teams.live.com)
function create_outlook_event(array $m, bool $invite): string {
    $ev = graph('POST', '/me/events', event_payload($m, $invite) + ['transactionId' => $m['id'], 'showAs' => 'busy',
        'location' => ['displayName' => 'Microsoft Teams', 'locationUri' => (string)($m['teamsUrl'] ?? ''), 'locationType' => 'default']]);
    return (string)$ev['id'];
}
function update_teams_event(array $m, bool $invite): void { graph('PATCH', '/me/events/' . rawurlencode($m['graphEventId']), event_payload($m, $invite)); }
function cancel_teams_event(array $m): void {
    $id = rawurlencode($m['graphEventId']);
    $c = graph_code('POST', "/me/events/$id/cancel", ['comment' => 'Reunión cancelada.']);
    if ($c === 400) $c = graph_code('DELETE', "/me/events/$id");
    if ($c >= 400 && $c !== 404) throw new GraphError("Outlook no ha podido cancelar el evento ($c).");
}

// ---------- Eventos de Outlook / Bookings ya existentes ----------
function outlook_events(): array {
    $from = gmdate('Y-m-d\TH:i:s\Z'); $to = gmdate('Y-m-d\TH:i:s\Z', time() + 60 * 86400);
    $d = graph('GET', '/me/calendarView?' . http_build_query(['startDateTime' => $from, 'endDateTime' => $to, '$top' => 100, '$orderby' => 'start/dateTime',
        '$select' => 'id,subject,start,end,isOnlineMeeting,onlineMeeting,attendees,isCancelled,bodyPreview,body,location,categories,transactionId,organizer'], '', '&', PHP_QUERY_RFC3986), null, null, 'UTC');
    $linked = get_json('ms/linked') ?? [];
    // Enlace de Teams: el de Outlook, o el que vaya pegado en el lugar o en el texto (Teams personal, citas de Bookings…)
    $teamsIn = function (array $e): string {
        $j = (string)($e['onlineMeeting']['joinUrl'] ?? '');
        if ($j !== '') return $j;
        $hay = ($e['location']['locationUri'] ?? '') . ' ' . ($e['location']['displayName'] ?? '') . ' ' . ($e['body']['content'] ?? '');
        return preg_match('#https://teams\.(?:microsoft\.com|live\.com|cloud\.microsoft)/[^\s"\'<>]+#i', html_entity_decode($hay), $x) ? $x[0] : '';
    };
    $out = [];
    foreach ($d['value'] ?? [] as $e) {
        if (!empty($e['isCancelled'])) continue;
        $join = $teamsIn($e);
        $others = array_filter($e['attendees'] ?? [], fn($a) => strtolower($a['emailAddress']['address'] ?? '') !== admin_email());
        $isBk = (bool)preg_match('/bookings|bookwithme|reserva/i', ($e['bodyPreview'] ?? '') . ' ' . implode(' ', $e['categories'] ?? []));
        if (!$join && !$others && !$isBk) continue; // eventos personales sin invitados ni Teams: no son citas
        $gs = [];
        foreach ($e['attendees'] ?? [] as $a) { $addr = strtolower($a['emailAddress']['address'] ?? ''); if ($addr && $addr !== admin_email() && count($gs) < MAX_GUESTS) { $nm = mb_substr((string)($a['emailAddress']['name'] ?? ''), 0, 80); $gs[] = ['name' => str_contains($nm, '@') ? '' : $nm, 'email' => $addr, 'phone' => '']; } }
        $guest = $gs ? ['name' => join_names(array_values(array_filter(array_column($gs, 'name')))), 'address' => $gs[0]['email']] : null;
        $start = strtotime($e['start']['dateTime'] . 'Z') * 1000; $end = strtotime($e['end']['dateTime'] . 'Z') * 1000;
        $mid = $linked[$e['id']] ?? (preg_match(ID_RE, (string)($e['transactionId'] ?? '')) && get_json('m/' . $e['transactionId']) ? $e['transactionId'] : null);
        // Si en Outlook la reunión ya tiene su Teams de empresa, la invitación pasa a usar ese enlace (sin tocar nada a mano)
        $biz = (string)($e['onlineMeeting']['joinUrl'] ?? '');
        if ($mid && $biz !== '' && ($mm = get_json('m/' . $mid)) && ($mm['teamsUrl'] ?? '') !== $biz && ($mm['status'] ?? '') !== 'cancelada') {
            $mm['teamsUrl'] = $biz; $mm['updatedAt'] = time() * 1000; $mm['sequence'] = (int)($mm['sequence'] ?? 0) + 1; set_json('m/' . $mid, $mm);
        }
        $preview = (string)($e['bodyPreview'] ?? '');
        $out[] = ['eventId' => $e['id'], 'subject' => $e['subject'] ?? '(sin asunto)', 'startUtc' => $start, 'endUtc' => $end,
            'fecha' => fecha_larga($start), 'hora' => hora_corta($start), 'horaFin' => hora_corta($end), 'joinUrl' => $join,
            'guestName' => $guest['name'] ?? '', 'guestEmail' => strtolower($guest['address'] ?? ''), 'guests' => $gs, 'meetingId' => $mid,
            'bookings' => (bool)preg_match('/bookings|bookwithme|reserva/i', $preview . ' ' . implode(' ', $e['categories'] ?? []))];
    }
    return $out;
}

// Crea la invitación de un evento de Outlook/Bookings y añade sus enlaces dentro del propio evento
function import_outlook_event(array $ev): array {
    $now = time() * 1000; $id = new_id();
    $dur = (int)round(($ev['endUtc'] - $ev['startUtc']) / 60000);
    $m = ['id' => $id, 'uid' => "$id@odlc.es", 'mode' => 'outlook', 'source' => $ev['bookings'] ? 'bookings' : 'outlook', 'tz' => ODLC_TZ, 'status' => 'programada', 'sequence' => 0,
        'createdAt' => $now, 'updatedAt' => $now, 'subject' => mb_substr($ev['subject'], 0, 160), 'guestName' => mb_substr($ev['guestName'], 0, 240), 'guestEmail' => $ev['guestEmail'], 'guests' => $ev['guests'] ?? [],
        'guestPhone' => '', 'description' => '', 'notes' => '', 'team' => [ODLC_NOMBRE], 'startUtc' => $ev['startUtc'], 'endUtc' => $ev['endUtc'], 'duration' => $dur,
        'teamsUrl' => $ev['joinUrl'], 'graphEventId' => $ev['eventId'], 'materials' => []];
    set_json("m/$id", $m);
    $linked = get_json('ms/linked') ?? []; $linked[$ev['eventId']] = $id; set_json('ms/linked', $linked);
    // Añade al principio del cuerpo del evento los enlaces de la invitación y de la tarjeta (sin tocar el bloque de Teams)
    try {
        $full = graph('GET', '/me/events/' . rawurlencode($ev['eventId']) . '?$select=body');
        $html = (string)($full['body']['content'] ?? '');
        $new = preg_match('/<body[^>]*>/i', $html) ? preg_replace('/(<body[^>]*>)/i', '$1' . links_html($m), $html, 1) : links_html($m) . $html;
        graph('PATCH', '/me/events/' . rawurlencode($ev['eventId']), ['body' => ['contentType' => 'HTML', 'content' => $new]]);
        $m['outlookUpdated'] = true;
    } catch (GraphError $e) { $m['outlookUpdated'] = false; }
    set_json("m/$id", $m);
    return $m;
}

// Reservas de Bookings: crea las invitaciones solas (se llama desde el panel y desde el cron)
function sync_bookings(): int {
    if (!cfg('auto_bookings', true) || !ms_ready()) return 0;
    $last = get_json('ms/sync'); if ($last && $last['at'] > time() - 120) return 0;
    set_json('ms/sync', ['at' => time()]);
    $n = 0;
    foreach (outlook_events() as $ev) if ($ev['bookings'] && !$ev['meetingId']) { import_outlook_event($ev); $n++; }
    return $n;
}

// ---------- Disponibilidad para la página de reservas ----------
function busy_intervals(int $fromMs, int $toMs): array {
    $d = graph('GET', '/me/calendarView?' . http_build_query(['startDateTime' => gmdate('Y-m-d\TH:i:s\Z', intdiv($fromMs, 1000)), 'endDateTime' => gmdate('Y-m-d\TH:i:s\Z', intdiv($toMs, 1000)),
        '$top' => 250, '$select' => 'start,end,showAs,isCancelled'], '', '&', PHP_QUERY_RFC3986), null, null, 'UTC');
    $busy = [];
    foreach ($d['value'] ?? [] as $e) {
        if (!empty($e['isCancelled']) || in_array($e['showAs'] ?? 'busy', ['free', 'workingElsewhere'], true)) continue;
        $busy[] = [strtotime($e['start']['dateTime'] . 'Z') * 1000, strtotime($e['end']['dateTime'] . 'Z') * 1000];
    }
    return $busy;
}

// ---------- Microsoft Bookings (página de reservas compartida de OdlC) ----------
// La API de Bookings solo funciona con páginas de reservas compartidas, no con la página personal «Bookings with me».
function iso_minutes(string $d): int {
    if (!preg_match('/^P(?:\d+D)?T?(?:(\d+)H)?(?:(\d+)M)?/', $d, $m)) return 30;
    return (int)($m[1] ?? 0) * 60 + (int)($m[2] ?? 0) + (preg_match('/^P(\d+)D/', $d, $dd) ? (int)$dd[1] * 1440 : 0);
}
function bk_businesses(): array {
    $d = graph('GET', '/solutions/bookingBusinesses?$select=id,displayName,email');
    return array_map(fn($b) => ['id' => $b['id'], 'nombre' => $b['displayName'] ?? $b['id']], $d['value'] ?? []);
}
function bk_business(): string { return (string)(get_json('bookings')['business'] ?? ''); }
function bk_services(string $biz): array {
    $cache = get_json('bk-cache');
    if ($cache && $cache['biz'] === $biz && $cache['at'] > time() - 600) return $cache['services'];
    $id = rawurlencode($biz);
    $d = graph('GET', "/solutions/bookingBusinesses/$id/services");
    $staff = graph('GET', "/solutions/bookingBusinesses/$id/staffMembers");
    $me = '';
    foreach ($staff['value'] ?? [] as $st) if (strtolower($st['emailAddress'] ?? '') === admin_email()) $me = $st['id'];
    if (!$me && !empty($staff['value'][0]['id'])) $me = $staff['value'][0]['id'];
    $services = [];
    foreach ($d['value'] ?? [] as $sv) {
        if (!empty($sv['isHiddenFromCustomers'])) continue;
        $services[] = ['id' => $sv['id'], 'nombre' => $sv['displayName'], 'duracion' => iso_minutes((string)($sv['defaultDuration'] ?? 'PT30M')),
            'descripcion' => trim(strip_tags((string)($sv['description'] ?? ''))), 'staff' => $me, 'online' => (bool)($sv['isLocationOnline'] ?? true)];
    }
    set_json('bk-cache', ['biz' => $biz, 'at' => time(), 'services' => $services]);
    return $services;
}
function bk_create(string $biz, array $sv, array $m, string $company, ?bool $online = null): array {
    $utc = fn(int $ms) => ['dateTime' => gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . '.0000000+00:00', 'timeZone' => 'UTC'];
    $online = $online ?? true;
    $teams = (string)($m['teamsUrl'] ?? '');
    // Un cliente de Bookings por invitado con correo (Bookings le envía la confirmación por correo)
    $customers = [];
    foreach (guests_of($m) as $g) if ($g['email'] !== '' || !$customers)
        $customers[] = ['@odata.type' => '#microsoft.graph.bookingCustomerInformation', 'name' => $g['name'] ?: $g['email'], 'emailAddress' => $g['email'],
            'phone' => $g['phone'], 'notes' => trim(($company ? "Empresa: $company\n" : '') . ($m['description'] ?? '')), 'timeZone' => ODLC_TZ];
    $body = ['serviceId' => $sv['id'], 'staffMemberIds' => $sv['staff'] ? [$sv['staff']] : [],
        'startDateTime' => $utc($m['startUtc']), 'endDateTime' => $utc($m['endUtc']),
        'isLocationOnline' => $online, 'optOutOfCustomerEmail' => false, 'customerTimeZone' => ODLC_TZ, 'smsNotificationsEnabled' => false,
        'serviceNotes' => 'Invitación OdlC: ' . invite_url($m) . ($teams !== '' && !$online ? "\nUnirse a Teams: $teams" : ''),
        'customers' => $customers, 'maximumAttendeesCount' => max(1, count($customers)), 'filledAttendeesCount' => count($customers)];
    // Enlace de Teams pegado a mano: va como lugar de la cita (Bookings no crea otro)
    if (!$online && $teams !== '') $body['serviceLocation'] = ['displayName' => 'Microsoft Teams', 'locationUri' => $teams, 'locationType' => 'default', 'address' => ['street' => $teams]];
    $ap = graph('POST', '/solutions/bookingBusinesses/' . rawurlencode($biz) . '/appointments', $body);
    $join = (string)($ap['joinWebUrl'] ?? '');
    return ['appointmentId' => $ap['id'] ?? '', 'joinUrl' => $join];
}
function bk_cancel(string $biz, string $apId): void {
    $c = graph_code('POST', '/solutions/bookingBusinesses/' . rawurlencode($biz) . '/appointments/' . rawurlencode($apId) . '/cancel', ['cancellationMessage' => 'La reunión con OdlC queda cancelada.']);
    if ($c >= 400 && $c !== 404) throw new GraphError("Bookings no ha podido cancelar la cita ($c).");
}
