<?php
// Imagen de cada invitación (1080×1080) para la vista previa grande de WhatsApp, Outlook, LinkedIn…
// Estilo «dashboard» como la propia invitación: fecha, hora, zona y duración. Sin nombres ni correos del invitado.
declare(strict_types=1);

const OG_W = 1200, OG_H = 900; // como la tarjeta de la invitación (cabecera, asunto, asistentes y fichas)

function og_rgb($im, string $hex) { return imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))); }
function og_font(string $n): string { return __DIR__ . '/img/' . $n . '.ttf'; }
function og_width(float $size, string $font, string $text, float $spacing = 0): float {
    $b = imagettfbbox($size, 0, $font, $text);
    return ($b[2] - $b[0]) + $spacing * max(0, mb_strlen($text) - 1);
}
// Texto con espaciado entre letras (para los rótulos en versalitas)
function og_text($im, float $size, string $font, $color, float $x, float $y, string $text, float $spacing = 0): void {
    if ($spacing <= 0) { imagettftext($im, $size, 0, (int)round($x), (int)round($y), $color, $font, $text); return; }
    foreach (mb_str_split($text) as $ch) {
        imagettftext($im, $size, 0, (int)round($x), (int)round($y), $color, $font, $ch);
        $x += og_width($size, $font, $ch) + $spacing;
    }
}
// Recorta el texto con «…» para que quepa en el ancho
function og_fit(float $size, string $font, string $text, float $max): string {
    if (og_width($size, $font, $text) <= $max) return $text;
    while (mb_strlen($text) > 1 && og_width($size, $font, $text . '…') > $max) $text = mb_substr($text, 0, -1);
    return rtrim($text) . '…';
}
function og_round_rect($im, int $x1, int $y1, int $x2, int $y2, int $r, $color): void {
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
}

function og_image_jpg(array $m): string {
    $v = public_view($m);
    $S = 2; // se dibuja al doble y se reduce: bordes suaves
    $im = imagecreatetruecolor(OG_W * $S, OG_H * $S);
    imagealphablending($im, true);
    $green = og_rgb($im, '#17302A'); $gold = og_rgb($im, '#A8843F'); $white = og_rgb($im, '#FFFFFF');
    $tile = og_rgb($im, '#F1EFEA'); $ink = og_rgb($im, '#17302A'); $muted = og_rgb($im, '#5F6B66'); $red = og_rgb($im, '#9B3127');
    $P = og_font('poppins-400'); $PM = og_font('poppins-500'); $L = og_font('lora-400');

    // Tarjeta cuadrada, como la invitación: cabecera verde con el logo y debajo asunto y fichas
    $W = OG_W * $S; $H = OG_H * $S;
    imagefilledrectangle($im, 0, 0, $W, $H, $white);
    $hh = 250 * $S;
    imagefilledrectangle($im, 0, 0, $W, $hh, $green);
    $logo = imagecreatefrompng(__DIR__ . '/img/logo-claro.png');
    $w = 300 * $S; $h = (int)round(imagesy($logo) * $w / imagesx($logo));
    imagecopyresampled($im, $logo, (int)(($W - $w) / 2), 48 * $S, 0, 0, $w, $h, imagesx($logo), imagesy($logo));
    imagedestroy($logo);
    $k = 'INVITACIÓN A REUNIÓN'; $ks = 19 * $S; $sp = 6 * $S;
    og_text($im, $ks, $PM, $gold, ($W - og_width($ks, $PM, $k, $sp)) / 2, 48 * $S + $h + 52 * $S, $k, $sp);
    imagefilledrectangle($im, (int)($W / 2 - 60 * $S), $hh - 5 * $S, (int)($W / 2 + 60 * $S), $hh + 1 * $S, $gold);

    $pad = 90 * $S; $cw = $W - 2 * $pad;
    $center = fn(float $size, string $font, $col, float $y, string $t) => og_text($im, $size, $font, $col, ($W - og_width($size, $font, $t)) / 2, $y, $t);
    $cancel = $v['estado'] === 'cancelada';
    $center(22 * $S, $PM, $cancel ? $red : $gold, 318 * $S, $cancel ? 'Reunión cancelada' : 'Reunión con OdlC');
    $fs = 46 * $S; while ($fs > 30 * $S && og_width($fs, $L, (string)$v['subject']) > $cw) $fs -= $S; // reduce antes de recortar
    $center($fs, $L, $ink, 382 * $S, og_fit($fs, $L, (string)$v['subject'], $cw));
    $center(21 * $S, $P, $muted, 428 * $S, og_fit(21 * $S, $P, implode(' · ', $v['team']), $cw));
    if ($v['guestName'] !== '') $center(21 * $S, $P, $muted, 462 * $S, og_fit(21 * $S, $P, 'para ' . $v['guestName'], $cw));

    $tiles = [
        ['FECHA', $v['fecha'], $v['dia']], ['HORA', $v['hora'] . ' h', 'hasta las ' . $v['horaFin']],
        ['ZONA', 'Madrid', $v['zona']], ['DURACIÓN', $v['duration'] . ' min', 'Microsoft Teams'],
    ];
    $gap = 24 * $S; $tw = (int)(($cw - $gap) / 2); $th = 168 * $S; $top = 500 * $S;
    foreach ($tiles as $i => [$lab, $big, $small]) {
        $tx = $pad + ($i % 2) * ($tw + $gap); $ty = $top + intdiv($i, 2) * ($th + $gap);
        og_round_rect($im, $tx, $ty, $tx + $tw, $ty + $th, 22 * $S, $tile);
        imagefilledrectangle($im, $tx, $ty + 18 * $S, $tx + 5 * $S, $ty + $th - 18 * $S, $gold);
        og_text($im, 16 * $S, $PM, $gold, $tx + 36 * $S, $ty + 46 * $S, $lab, 4 * $S);
        og_text($im, 40 * $S, $PM, $ink, $tx + 36 * $S, $ty + 102 * $S, og_fit(40 * $S, $PM, $big, $tw - 60 * $S));
        og_text($im, 21 * $S, $P, $muted, $tx + 36 * $S, $ty + 142 * $S, og_fit(21 * $S, $P, $small, $tw - 60 * $S));
    }


    $out = imagecreatetruecolor(OG_W, OG_H);
    imagecopyresampled($out, $im, 0, 0, 0, 0, OG_W, OG_H, OG_W * $S, OG_H * $S);
    imagedestroy($im);
    // JPEG ligero (WhatsApp solo muestra la imagen grande si pesa poco: < 300 KB)
    ob_start(); imagejpeg($out, null, 90); imagedestroy($out);
    return (string)ob_get_clean();
}
