<?php
// Página de reservas: tipos de cita, horario y huecos libres según el calendario de Outlook.
declare(strict_types=1);

function booking_settings(): array {
    $def = [
        'tipos' => [
            ['id' => 'sesion', 'nombre' => 'Sesión inicial gratuita', 'duracion' => 30, 'descripcion' => 'Primera conversación para conocer su empresa y ver cómo puede ayudarle OdlC.'],
            ['id' => 'diagnostico', 'nombre' => 'Arranque del Diagnóstico OdlC', 'duracion' => 60, 'descripcion' => 'Reunión de inicio para quienes ya han contratado el Diagnóstico.'],
            ['id' => 'reunion', 'nombre' => 'Reunión OdlC', 'duracion' => 60, 'descripcion' => 'Reunión de trabajo con Isabel Olivares de la Cerda.', 'oculta' => true],
        ],
        // Franjas por día de la semana (1 = lunes … 5 = viernes), hora de Madrid
        'horario' => ['1' => [['09:30', '14:00'], ['16:00', '18:30']], '2' => [['09:30', '14:00'], ['16:00', '18:30']], '3' => [['09:30', '14:00'], ['16:00', '18:30']],
                      '4' => [['09:30', '14:00'], ['16:00', '18:30']], '5' => [['09:30', '14:00']]],
        'antelacion_horas' => 24, 'dias' => 21, 'paso_min' => 30, 'margen_min' => 15, 'festivos' => [],
    ];
    $s = get_json('reservas') ?? [];
    return array_replace($def, $s);
}

// Si hay una página de reservas compartida de Bookings elegida, los tipos de cita son sus servicios.
// $todas = false: solo los públicos (la lista de /reservar). Los ocultos se abren solo con su enlace.
function booking_types(bool $todas = false): array {
    $t = booking_all_types();
    return $todas ? $t : array_values(array_filter($t, fn($x) => empty($x['oculta'])));
}
function booking_all_types(): array {
    $biz = bk_business();
    if ($biz && ms_ready()) {
        try { return array_map(fn($sv) => $sv + ['bookings' => true], bk_services($biz)); } catch (GraphError $e) { error_log('Bookings: ' . $e->getMessage()); }
    }
    return booking_settings()['tipos'];
}
function booking_type(string $id): ?array {
    $all = booking_types(true);
    foreach ($all as $t) if ($t['id'] === $id) return $t;
    // «reunion»: el servicio oculto de Bookings cuyo nombre lleva «reunión» (o el primero oculto)
    if ($id === 'reunion') {
        $ocultos = array_values(array_filter($all, fn($x) => !empty($x['oculta'])));
        foreach ($ocultos as $t) if (preg_match('/reuni/iu', $t['nombre'])) return $t;
        if ($ocultos) return $ocultos[0];
        foreach (booking_settings()['tipos'] as $t) if ($t['id'] === 'reunion') return $t;
    }
    return null;
}

// Huecos libres por día: [ 'YYYY-MM-DD' => ['09:30', '10:00', …] ]
function booking_slots(array $tipo): array {
    $s = booking_settings();
    $dur = (int)$tipo['duracion'] * 60000; $step = (int)$s['paso_min'] * 60000; $margin = (int)$s['margen_min'] * 60000;
    $earliest = (time() + (int)$s['antelacion_horas'] * 3600) * 1000;
    $from = (new DateTimeImmutable('today', tz())); $to = $from->modify('+' . (int)$s['dias'] . ' days');
    $busy = busy_intervals($from->getTimestamp() * 1000, $to->getTimestamp() * 1000);
    $out = [];
    for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        if (in_array($date, $s['festivos'], true)) continue;
        foreach ($s['horario'][$d->format('N')] ?? [] as [$a, $b]) {
            $start = madrid_to_utc($date, $a); $end = madrid_to_utc($date, $b);
            if ($start === null || $end === null) continue;
            for ($t = $start; $t + $dur <= $end; $t += $step) {
                if ($t < $earliest) continue;
                $free = true;
                foreach ($busy as [$bs, $be]) if ($t < $be + $margin && $t + $dur > $bs - $margin) { $free = false; break; }
                if ($free) $out[$date][] = hora_corta($t);
            }
        }
    }
    return $out;
}
