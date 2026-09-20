<?php

namespace App\Support\Social;

use RuntimeException;

class SocialCard
{
    public static function level(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : 'Unavailable';
    }

    public static function peaks(array $snapshot): array
    {
        $rows = collect($snapshot['strike_data']);

        return [
            'positive' => $rows->where('net_gex', '>', 0)->sortByDesc('net_gex')->first()['strike'] ?? null,
            'negative' => $rows->where('net_gex', '<', 0)->sortBy('net_gex')->first()['strike'] ?? null,
            'hvl' => $snapshot['hvl'] ?? null,
            'positive_value' => $rows->where('net_gex', '>', 0)->max('net_gex'),
            'negative_value' => $rows->where('net_gex', '<', 0)->min('net_gex'),
            'total' => $rows->sum('net_gex'),
        ];
    }

    public static function exposure(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Unavailable';
        }
        $v = (float) $value;
        $sign = $v > 0 ? '+' : ($v < 0 ? '-' : '');
        foreach ([1e12 => 'T', 1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $scale => $unit) {
            if (abs($v) >= $scale) {
                return $sign.self::level(abs($v) / $scale).$unit;
            }
        }

        return $sign.self::level(abs($v));
    }

    public function render(array $snapshot, string $session): string
    {
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            throw new RuntimeException('Social images require PHP GD with FreeType support.');
        }
        $font = resource_path('fonts/atkinson/AtkinsonHyperlegible-Regular.ttf');
        $bold = resource_path('fonts/atkinson/AtkinsonHyperlegible-Bold.ttf');
        if (! is_file($font) || ! is_file($bold)) {
            throw new RuntimeException('The bundled social image fonts are missing.');
        }
        $im = imagecreatetruecolor(1600, 1000);
        try {
            $color = fn ($r, $g, $b) => imagecolorallocate($im, $r, $g, $b);
            $bg = $color(10, 15, 24);
            $panel = $color(23, 31, 44);
            $text = $color(239, 245, 255);
            $muted = $color(163, 182, 202);
            $green = $color(102, 220, 176);
            $red = $color(244, 156, 140);
            $blue = $color(144, 195, 255);
            $line = $color(52, 66, 85);
            imagefill($im, 0, 0, $bg);
            $write = function ($x, $y, $size, $value, $ink = null, $heavy = false) use ($im, $font, $bold, $text) {
                imagettftext($im, $size, 0, $x, $y, $ink ?? $text, $heavy ? $bold : $font, (string) $value);
            };
            $write(65, 66, 19, 'NEXT-SESSION PREPARATION', $blue, true);
            $write(65, 135, 46, $snapshot['symbol'].' / GEX levels', $text, true);
            $write(65, 181, 22, 'For '.$session.'   |   Source EOD '.substr($snapshot['data_date'], 0, 10).'   |   2W expiry scope', $muted);
            $peaks = self::peaks($snapshot);
            foreach ([
                ['Total net GEX', $peaks['total'], $peaks['total'] < 0 ? $red : $green, 'All '.count($snapshot['strike_data']).' numeric strikes'],
                ['Largest positive GEX', $peaks['positive_value'], $green, 'Strike '.self::level($peaks['positive'])],
                ['Largest negative GEX', $peaks['negative_value'], $red, 'Strike '.self::level($peaks['negative'])],
            ] as $i => [$label, $value, $ink, $detail]) {
                $x = 65 + $i * 498;
                imagefilledrectangle($im, $x, 212, $x + 475, 365, $panel);
                imagefilledrectangle($im, $x, 212, $x + 72, 216, $ink);
                $write($x + 23, 252, 21, $label, $muted);
                $write($x + 23, 308, 35, self::exposure($value), $ink, true);
                $write($x + 23, 342, 18, $detail, $muted);
            }
            $rows = $snapshot['strike_data'];
            usort($rows, fn ($a, $b) => $a['strike'] <=> $b['strike']);
            $totalRows = count($rows);
            $absolute = array_sum(array_map(fn ($row) => abs((float) $row['net_gex']), $rows));
            // Trim at most 1% of absolute exposure from either tail for feed readability.
            // All original rows remain in the frozen source JSON.
            $lo = 0;
            $hi = count($rows) - 1;
            $trimmed = 0.0;
            while ($absolute > 0 && $lo < $hi && $trimmed + abs($rows[$lo]['net_gex']) <= $absolute * 0.01) {
                $trimmed += abs($rows[$lo++]['net_gex']);
            }
            $tail = 0.0;
            while ($absolute > 0 && $hi > $lo && $tail + abs($rows[$hi]['net_gex']) <= $absolute * 0.01) {
                $tail += abs($rows[$hi--]['net_gex']);
            }
            $rows = array_slice($rows, $lo, $hi - $lo + 1);
            $coverage = $absolute > 0 ? round(100 * ($absolute - $trimmed - $tail) / $absolute, 1) : 100;
            $min = (float) $rows[0]['strike'];
            $max = (float) $rows[count($rows) - 1]['strike'];
            $range = max(1, $max - $min);
            $limit = max(1, ...array_map(fn ($row) => abs((float) $row['net_gex']), $rows)) * 1.1;
            $compact = static function (float $v): string {
                foreach ([1e12 => 'T', 1e9 => 'B', 1e6 => 'M', 1e3 => 'K'] as $scale => $unit) {
                    if (abs($v) >= $scale) {
                        return round($v / $scale, 1).$unit;
                    }
                }

                return (string) round($v, 1);
            };
            $write(65, 413, 22, 'NET GEX BY STRIKE', $text, true);
            $write(870, 411, 18, 'Positive', $green);
            $write(1000, 411, 18, 'Negative', $red);
            $write(1145, 411, 18, 'Zero baseline', $muted);
            $left = 160;
            $right = 1520;
            $zero = 619;
            $amplitude = 166;
            foreach ([-1, -0.5, 0, 0.5, 1] as $fraction) {
                $y = (int) round($zero - $fraction * $amplitude);
                imageline($im, $left, $y, $right, $y, $fraction === 0 ? $muted : $line);
                $write(65, $y + 7, 17, $compact($fraction * $limit), $muted);
            }
            $gaps = [];
            for ($i = 1; $i < count($rows); $i++) {
                $gaps[] = (float) $rows[$i]['strike'] - (float) $rows[$i - 1]['strike'];
            }
            $width = max(1, min(14, (int) ((count($gaps) ? min($gaps) : 1) / $range * ($right - $left) * .8)));
            foreach ($rows as $row) {
                if ($row['net_gex'] == 0) {
                    continue;
                }
                $x = (int) ($left + ((float) $row['strike'] - $min) / $range * ($right - $left));
                $y = (int) round($zero - $row['net_gex'] / $limit * $amplitude);
                imagefilledrectangle($im, $x, min($zero, $y), $x + $width, max($zero, $y), $row['net_gex'] > 0 ? $green : $red);
            }
            for ($i = 0; $i <= 6; $i++) {
                $x = (int) ($left + ($right - $left) * $i / 6);
                $write($x - 25, 826, 19, self::level($min + ($max - $min) * $i / 6), $muted);
            }
            $write(65, 871, 18, 'Focused range: '.count($rows).'/'.$totalRows.' strikes, '.$coverage.'% of absolute GEX. Individual strikes; no grouping.', $muted);
            $write(65, 905, 17, 'Expiries '.min($snapshot['expiration_dates']).' to '.max($snapshot['expiration_dates']).'. Prior EOD inputs; not live session values.', $muted);
            imageline($im, 65, 934, 1535, 934, $line);
            $write(65, 977, 23, 'GEX OPTIONS', $text, true);
            $write(1220, 977, 22, 'gexoptions.com', $blue);
            ob_start();
            imagepng($im);

            return ob_get_clean();
        } finally {
            imagedestroy($im);
        }
    }
}
