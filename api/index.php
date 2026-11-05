<?php
// citas.odlc.es/api/* — API del panel privado, invitaciones públicas y página de reservas.
declare(strict_types=1);
require __DIR__ . '/_lib/core.php';
require __DIR__ . '/_lib/meeting.php';
require __DIR__ . '/_lib/graph.php';
require __DIR__ . '/_lib/booking.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = '/' . trim((string)($_GET['r'] ?? ''), '/');

header('Cache-Control: no-store'); // ninguna respuesta de la API (ni las redirecciones de entrada) se guarda en la caché de Hostinger
try {
    // ===== Público =====
    if ($path === '/estado' && $method === 'GET') send_json(['ok' => true]);

    // Invitación (solo lectura)
    if (preg_match('#^/invitacion/([A-Za-z0-9_-]{22})$#', $path, $x) && $method === 'GET') {
        $m = get_json('m/' . $x[1]);
        if (!$m) send_json(['error' => 'Invitación no disponible'], 404);
        send_json(['invitation' => public_view($m)]);
    }

    // Reservas: tipos y huecos libres
    if ($path === '/reservas' && $method === 'GET') {
        if (!ms_ready()) send_json(['disponible' => false, 'tipos' => []]);
        $tipos = array_map(fn($t) => ['id' => $t['id'], 'nombre' => $t['nombre'], 'duracion' => $t['duracion'], 'descripcion' => $t['descripcion'] ?? ''], booking_types());
        $tipo = booking_type((string)($_GET['tipo'] ?? '')) ;
        send_json(['disponible' => true, 'tipos' => $tipos, 'huecos' => $tipo ? booking_slots($tipo) : new stdClass()]);
    }
    if ($path === '/reservar' && $method === 'POST') {
        if (!same_origin()) send_json(['error' => 'Origen no permitido'], 403);
        $b = body_json();
        if (!empty($b['web'])) send_json(['error' => 'No se ha podido reservar.'], 400); // campo trampa para robots
        $rk = 'rl/res-' . substr(hash('sha256', client_ip()), 0, 32);
        $rl = get_json($rk) ?? ['n' => 0, 'from' => time()];
        if ($rl['from'] < time() - 3600) $rl = ['n' => 0, 'from' => time()];
        if ($rl['n'] >= 4) send_json(['error' => 'Ha hecho varias reservas seguidas. Inténtelo más tarde o escriba a ' . ODLC_EMAIL . '.'], 429);
        $tipo = booking_type((string)($b['tipo'] ?? ''));
        if (!$tipo || !ms_ready()) send_json(['error' => 'Las reservas no están disponibles ahora mismo.'], 503);
        $fields = [];
        $name = clean_str($b['nombre'] ?? '', 120); $email = mb_strtolower(clean_str($b['email'] ?? '', 160)); $phone = clean_str($b['telefono'] ?? '', 30);
        $company = clean_str($b['empresa'] ?? '', 120); $notes = clean_str($b['mensaje'] ?? '', 1000);
        if ($name === '') $fields['nombre'] = 'Escriba su nombre.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fields['email'] = 'Escriba un correo válido.';
        if ($phone !== '' && !preg_match('/^\+?\d{9,15}$/', preg_replace('/[^\d+]/', '', $phone))) $fields['telefono'] = 'Teléfono no válido.';
        if (empty($b['acepto'])) $fields['acepto'] = 'Debe aceptar la política de privacidad.';
        $date = (string)($b['fecha'] ?? ''); $time = (string)($b['hora'] ?? '');
        if (!in_array($time, booking_slots($tipo)[$date] ?? [], true)) $fields['hora'] = 'Ese hueco ya no está libre. Elija otro.';
        if ($fields) send_json(['error' => 'Revise los datos.', 'fields' => $fields], 422);
        $start = madrid_to_utc($date, $time); $now = time() * 1000; $id = new_id();
        $m = ['id' => $id, 'uid' => "$id@odlc.es", 'mode' => 'auto', 'source' => 'reserva', 'tz' => ODLC_TZ, 'status' => 'programada', 'sequence' => 0,
            'createdAt' => $now, 'updatedAt' => $now, 'subject' => $tipo['nombre'] . ' · ' . ($company ?: $name), 'guestName' => $name, 'guestEmail' => $email,
            'guestPhone' => $phone, 'description' => $notes, 'notes' => $company ? "Empresa: $company" : '', 'team' => [ODLC_NOMBRE],
            'startUtc' => $start, 'endUtc' => $start + $tipo['duracion'] * 60000, 'duration' => (int)$tipo['duracion'], 'materials' => [], 'sendInvite' => true];
        if (!empty($tipo['bookings'])) {
            // Cita creada en Microsoft Bookings: Bookings envía la confirmación al cliente y la pone en el calendario de Isabel
            $m['source'] = 'bookings'; $m['mode'] = 'bookings'; $m['bookingsBusiness'] = bk_business();
            $ap = bk_create(bk_business(), $tipo, $m, $company);
            $m['bookingAppointmentId'] = $ap['appointmentId']; $m['teamsUrl'] = $ap['joinUrl'];
            $linked = get_json('ms/linked') ?? []; $linked['bk:' . $ap['appointmentId']] = $id; set_json('ms/linked', $linked);
        } else {
            $ev = create_teams_event($m, true); // Outlook envía al cliente la convocatoria oficial con Teams
            $m['teamsUrl'] = $ev['joinUrl']; $m['graphEventId'] = $ev['eventId'];
        }
        set_json("m/$id", $m);
        $rl['n']++; set_json($rk, $rl);
        send_json(['id' => $id, 'url' => invite_url($m)], 201);
    }

    // ===== Acceso: solo «Iniciar sesión con Microsoft» y solo cuentas autorizadas =====
    if ($path === '/entrar' && $method === 'GET') {
        if (missing_config()) { header('Location: /?acceso=error&detalle=' . rawurlencode('El acceso con Microsoft aún no está configurado.'), true, 303); exit; }
        header('Location: ' . ms_authorize_url('login'), true, 303); exit;
    }
    if ($path === '/logout' && $method === 'POST') { header('Set-Cookie: ' . clear_cookie()); send_json(['ok' => true]); }

    // Vuelta desde Microsoft (inicio de sesión o conexión del calendario).
    // La cookie SameSite=Strict no viaja en esta redirección: la protegen el «state» de un solo uso, PKCE y el nonce.
    if ($path === '/ms/callback' && $method === 'GET') {
        $target = '/';
        try {
            $r = ms_callback($_GET);
            if ($r['purpose'] === 'login') header('Set-Cookie: ' . session_cookie($r['email']));
            else $target = '/?ms=ok';
        } catch (Throwable $e) { $target = '/?acceso=error&detalle=' . rawurlencode(mb_substr($e->getMessage(), 0, 250)); }
        send_html('<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><meta http-equiv="refresh" content="0;url=' . esc($target) . '"><title>OdlC</title><p style="font-family:sans-serif">Volviendo al panel… <a href="' . esc($target) . '">Continuar</a></p>');
    }

    // ===== Privado =====
    $user = current_user();
    if (!$user) send_json(['error' => 'No autorizado', 'missing' => missing_config()], 401);
    if ($method !== 'GET' && !same_origin()) send_json(['error' => 'Origen no permitido'], 403);

    if ($path === '/session') send_json(['ok' => true, 'user' => $user]);

    // Lista de personas autorizadas (solo la administradora la gestiona)
    if ($path === '/usuarios' && $method === 'GET') send_json(['usuarios' => array_values(users())]);
    if (str_starts_with($path, '/usuarios') && empty($user['admin'])) send_json(['error' => 'Solo la administradora puede cambiar los accesos.'], 403);
    if ($path === '/usuarios' && $method === 'POST') {
        $b = body_json(); $email = strtolower(trim((string)($b['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) send_json(['error' => 'Escribe el correo de su cuenta de Microsoft.'], 422);
        $all = users(); $all[$email] = ['email' => $email, 'nombre' => clean_str($b['nombre'] ?? '', 120), 'admin' => false, 'alta' => time() * 1000];
        save_users($all);
        send_json(['usuarios' => array_values(users())], 201);
    }
    if (preg_match('#^/usuarios/(.+)$#', $path, $x) && $method === 'DELETE') {
        $email = strtolower(rawurldecode($x[1]));
        if ($email === admin_email()) send_json(['error' => 'La administradora no se puede quitar.'], 422);
        $all = users(); unset($all[$email]); save_users($all);
        send_json(['usuarios' => array_values(users())]);
    }
    if ($path === '/ms/estado') send_json(ms_status() + ['adminEmail' => admin_email(), 'autoBookings' => (bool)cfg('auto_bookings', true)]);
    if ($path === '/ms/conectar' && $method === 'GET') {
        if (empty($user['admin'])) { header('Location: /', true, 303); exit; }
        if (ms_missing()) { header('Location: /?ms=error&detalle=' . rawurlencode('Faltan las claves de Microsoft Entra ID.')); exit; }
        header('Location: ' . ms_authorize_url(), true, 303); exit;
    }
    if ($path === '/ms/desconectar' && $method === 'POST') { if (empty($user['admin'])) send_json(['error' => 'Solo la administradora.'], 403); del_key('ms/token'); send_json(['ok' => true]); }

    // Página de reservas compartida de Bookings
    if ($path === '/bookings' && $method === 'GET') {
        if (!ms_ready()) send_json(['negocios' => [], 'elegido' => '']);
        try { $list = bk_businesses(); } catch (GraphError $e) { send_json(['negocios' => [], 'elegido' => bk_business(), 'error' => $e->getMessage()]); }
        $sel = bk_business(); $services = [];
        if ($sel) { try { $services = bk_services($sel); } catch (GraphError $e) {} }
        send_json(['negocios' => $list, 'elegido' => $sel, 'servicios' => $services]);
    }
    if ($path === '/bookings' && $method === 'POST') {
        if (empty($user['admin'])) send_json(['error' => 'Solo la administradora.'], 403);
        $biz = (string)(body_json()['business'] ?? '');
        set_json('bookings', ['business' => $biz]); del_key('bk-cache');
        send_json(['ok' => true]);
    }

    // Eventos de Outlook y Bookings
    if ($path === '/outlook/eventos' && $method === 'GET') { $n = sync_bookings(); send_json(['events' => outlook_events(), 'importadas' => $n]); }
    if ($path === '/outlook/importar' && $method === 'POST') {
        $id = (string)(body_json()['eventId'] ?? '');
        foreach (outlook_events() as $ev) if ($ev['eventId'] === $id) {
            if ($ev['meetingId']) send_json(['meeting' => admin_view(get_json('m/' . $ev['meetingId']))]);
            send_json(['meeting' => admin_view(import_outlook_event($ev))], 201);
        }
        send_json(['error' => 'Evento no encontrado en los próximos 60 días.'], 404);
    }

    // Reuniones
    if ($path === '/reuniones' && $method === 'GET') {
        $items = list_meetings();
        usort($items, fn($a, $b) => $a['startUtc'] <=> $b['startUtc']);
        send_json(['meetings' => array_map('admin_view', $items)]);
    }
    if ($path === '/reuniones' && $method === 'POST') {
        $b = body_json();
        $mode = ($b['mode'] ?? '') === 'auto' ? 'auto' : 'manual';
        $v = validate_input($b, $mode === 'manual');
        if (isset($v['errors'])) send_json(['error' => 'Revisa los campos marcados.', 'fields' => $v['errors']], 422);
        $invite = $mode === 'auto' && !empty($b['sendInvite']);
        if ($invite && $v['data']['guestEmail'] === '') send_json(['error' => 'Falta el correo del invitado.', 'fields' => ['guestEmail' => 'Escribe el correo para enviar la convocatoria.']], 422);
        $now = time() * 1000; $id = new_id();
        $m = $v['data'] + ['id' => $id, 'uid' => "$id@odlc.es", 'mode' => $mode, 'source' => 'panel', 'tz' => ODLC_TZ, 'status' => 'programada', 'sequence' => 0,
            'createdAt' => $now, 'updatedAt' => $now, 'sendInvite' => $invite, 'materials' => []];
        if ($mode === 'auto') { $ev = create_teams_event($m, $invite); $m['teamsUrl'] = $ev['joinUrl']; $m['graphEventId'] = $ev['eventId']; }
        set_json("m/$id", $m);
        send_json(['meeting' => admin_view($m)], 201);
    }

    if (preg_match('#^/reuniones/([A-Za-z0-9_-]{22})(?:/(cancelar|materiales)(?:/([A-Za-z0-9_-]{16}))?)?$#', $path, $x)) {
        $key = 'm/' . $x[1]; $m = get_json($key); $sub = $x[2] ?? ''; $fid = $x[3] ?? '';
        if (!$m) send_json(['error' => 'Reunión no encontrada'], 404);

        if ($sub === '' && $method === 'PUT') {
            if ($m['status'] === 'cancelada') send_json(['error' => 'La reunión está cancelada.'], 409);
            $b = body_json();
            if ($m['mode'] !== 'manual') $b['teamsUrl'] = $m['teamsUrl'];
            $v = validate_input($b, true, true);
            if (isset($v['errors'])) send_json(['error' => 'Revisa los campos marcados.', 'fields' => $v['errors']], 422);
            $next = array_replace($m, $v['data'], ['updatedAt' => time() * 1000, 'sequence' => ($m['sequence'] ?? 0) + 1]);
            if (!empty($m['graphEventId']) && $m['mode'] === 'auto') { $next['sendInvite'] = !empty($b['sendInvite']) || !empty($m['sendInvite']); update_teams_event($next, $next['sendInvite']); }
            set_json($key, $next);
            send_json(['meeting' => admin_view($next)]);
        }
        if ($sub === 'cancelar' && $method === 'POST') {
            if ($m['status'] !== 'cancelada') {
                if (!empty($m['graphEventId']) && $m['mode'] === 'auto') cancel_teams_event($m);
                if (!empty($m['bookingAppointmentId'])) bk_cancel((string)$m['bookingsBusiness'], (string)$m['bookingAppointmentId']);
                $m = array_replace($m, ['status' => 'cancelada', 'updatedAt' => time() * 1000, 'cancelledAt' => time() * 1000, 'sequence' => ($m['sequence'] ?? 0) + 1]);
                set_json($key, $m);
            }
            $txt = implode("\n", [!empty($m['guestName']) ? "Hola, {$m['guestName']}:" : 'Hola:', '',
                'Te escribo para avisarte de que la reunión con OdlC prevista para el ' . mb_strtolower(fecha_larga($m['startUtc'])) . ' a las ' . hora_corta($m['startUtc']) . ' h (Madrid) queda cancelada.', '',
                'Si la habías añadido a tu calendario, puedes actualizarla aquí:', invite_url($m), '', 'Un saludo,', '', ODLC_NOMBRE, ODLC_EMPRESA]);
            send_json(['meeting' => admin_view($m), 'cancelText' => $txt]);
        }
        if ($sub === 'materiales' && $fid === '' && $method === 'POST') {
            $list = $m['materials'] ?? [];
            if (count($list) >= MAX_MATERIALS) send_json(['error' => 'Máximo ' . MAX_MATERIALS . ' materiales por reunión.'], 422);
            $newId = b64u(random_bytes(12));
            $ctype = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
            if ($ctype === 'application/json') {
                $b = body_json(); $url = trim((string)($b['url'] ?? ''));
                if (parse_url($url, PHP_URL_SCHEME) !== 'https') send_json(['error' => 'Pega un enlace que empiece por https://'], 422);
                $item = ['id' => $newId, 'kind' => 'link', 'name' => clean_file_name((string)($b['name'] ?? '') ?: (string)parse_url($url, PHP_URL_HOST)), 'url' => $url];
            } else {
                if (!isset(FILE_TYPES[$ctype])) send_json(['error' => 'Tipo de archivo no admitido. Usa PDF, Word, Excel, PowerPoint, imagen, texto o CSV.'], 415);
                if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_FILE) send_json(['error' => 'El archivo supera 10 MB. Súbelo a OneDrive y añade el enlace.'], 413);
                $data = (string)file_get_contents('php://input');
                if ($data === '') send_json(['error' => 'El archivo está vacío.'], 422);
                if (strlen($data) > MAX_FILE) send_json(['error' => 'El archivo supera 10 MB. Súbelo a OneDrive y añade el enlace.'], 413);
                $dir = path_for('f/' . $m['id']); if (!is_dir($dir)) mkdir($dir, 0750, true);
                file_put_contents($dir . '/' . $newId, $data, LOCK_EX);
                $item = ['id' => $newId, 'kind' => 'file', 'name' => clean_file_name(rawurldecode((string)($_SERVER['HTTP_X_FILE_NAME'] ?? 'documento'))), 'type' => $ctype, 'size' => strlen($data)];
            }
            $m['materials'] = [...$list, $item]; $m['updatedAt'] = time() * 1000;
            set_json($key, $m);
            send_json(['meeting' => admin_view($m)], 201);
        }
        if ($sub === 'materiales' && $fid !== '' && $method === 'DELETE') {
            $item = null; foreach ($m['materials'] ?? [] as $f) if ($f['id'] === $fid) $item = $f;
            if (!$item) send_json(['error' => 'Material no encontrado'], 404);
            if ($item['kind'] === 'file' && is_file(file_path($m['id'], $fid))) unlink(file_path($m['id'], $fid));
            $m['materials'] = array_values(array_filter($m['materials'], fn($f) => $f['id'] !== $fid)); $m['updatedAt'] = time() * 1000;
            set_json($key, $m);
            send_json(['meeting' => admin_view($m)]);
        }
    }
    send_json(['error' => 'No encontrado'], 404);
} catch (GraphError $e) {
    send_json(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('OdlC API: ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
    send_json(['error' => 'Error del servidor. Inténtalo de nuevo.'], 500);
}
