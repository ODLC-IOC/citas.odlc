<?php
// Imagen de cada invitación (1200×630) para la vista previa grande de WhatsApp, Outlook, LinkedIn…
// Estilo «dashboard» como la propia invitación: fecha, hora, zona y duración. Sin nombres ni correos del invitado.
declare(strict_types=1);

const OG_W = 1200, OG_H = 630;

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

    imagefilledrectangle($im, 0, 0, OG_W * $S, OG_H * $S, $white);
    // Columna verde con el logo
    $lw = 400 * $S;
    imagefilledrectangle($im, 0, 0, $lw, OG_H * $S, $green);
    $logo = imagecreatefrompng(__DIR__ . '/img/logo-claro.png');
    $w = 280 * $S; $h = (int)round(imagesy($logo) * $w / imagesx($logo));
    imagecopyresampled($im, $logo, (int)(($lw - $w) / 2), 200 * $S, 0, 0, $w, $h, imagesx($logo), imagesy($logo));
    imagedestroy($logo);
    $k = 'INVITACIÓN A REUNIÓN'; $ks = 15 * $S; $sp = 4.5 * $S;
    og_text($im, $ks, $PM, $gold, ($lw - og_width($ks, $PM, $k, $sp)) / 2, 200 * $S + $h + 62 * $S, $k, $sp);
    imagefilledrectangle($im, (int)($lw / 2 - 32 * $S), 200 * $S + $h + 92 * $S, (int)($lw / 2 + 32 * $S), 200 * $S + $h + 95 * $S, $gold);
    $site = preg_replace('#^https?://#', '', site_url());
    og_text($im, 15 * $S, $P, $white, ($lw - og_width(15 * $S, $P, $site)) / 2, (OG_H - 44) * $S, $site);

    // Columna blanca: asunto y fichas
    $x = 460 * $S; $right = (OG_W - 60) * $S; $cw = $right - $x;
    $cancel = $v['estado'] === 'cancelada';
    og_text($im, 18 * $S, $PM, $cancel ? $red : $gold, $x, 92 * $S, $cancel ? 'Reunión cancelada' : 'Reunión con OdlC');
    $fs = 38 * $S; while ($fs > 28 * $S && og_width($fs, $L, (string)$v['subject']) > $cw) $fs -= $S; // reduce antes de recortar
    og_text($im, $fs, $L, $ink, $x, 150 * $S, og_fit($fs, $L, (string)$v['subject'], $cw));
    $team = og_fit(17 * $S, $P, implode(' · ', $v['team']), $cw);
    og_text($im, 17 * $S, $P, $muted, $x, 190 * $S, $team);

    $tiles = [
        ['FECHA', $v['fecha'], $v['dia']], ['HORA', $v['hora'] . ' h', 'hasta las ' . $v['horaFin']],
        ['ZONA', 'Madrid', $v['zona']], ['DURACIÓN', $v['duration'] . ' min', 'Microsoft Teams'],
    ];
    $gap = 20 * $S; $tw = (int)(($cw - $gap) / 2); $th = 160 * $S; $top = 232 * $S;
    foreach ($tiles as $i => [$lab, $big, $small]) {
        $tx = $x + ($i % 2) * ($tw + $gap); $ty = $top + intdiv($i, 2) * ($th + $gap);
        og_round_rect($im, $tx, $ty, $tx + $tw, $ty + $th, 18 * $S, $tile);
        imagefilledrectangle($im, $tx, $ty + 14 * $S, $tx + 4 * $S, $ty + $th - 14 * $S, $gold);
        og_text($im, 13 * $S, $PM, $gold, $tx + 30 * $S, $ty + 44 * $S, $lab, 3 * $S);
        og_text($im, 31 * $S, $PM, $ink, $tx + 30 * $S, $ty + 94 * $S, og_fit(31 * $S, $PM, $big, $tw - 50 * $S));
        og_text($im, 17 * $S, $P, $muted, $tx + 30 * $S, $ty + 130 * $S, og_fit(17 * $S, $P, $small, $tw - 50 * $S));
    }

    $out = imagecreatetruecolor(OG_W, OG_H);
    imagecopyresampled($out, $im, 0, 0, 0, 0, OG_W, OG_H, OG_W * $S, OG_H * $S);
    imagedestroy($im);
    // JPEG ligero (WhatsApp solo muestra la imagen grande si pesa poco: < 300 KB)
    ob_start(); imagejpeg($out, null, 90); imagedestroy($out);
    return (string)ob_get_clean();
}
