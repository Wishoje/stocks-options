<?php

namespace App\Support;
final class Symbols {
    public static function canon(?string $s): string {
        return strtoupper(trim($s ?? ''));
    }
}
