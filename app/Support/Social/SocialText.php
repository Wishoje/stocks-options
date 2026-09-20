<?php

namespace App\Support\Social;

use Illuminate\Validation\ValidationException;

class SocialText
{
    public static function draft(array $snapshot, string $session): string
    {
        $p = SocialCard::peaks($snapshot);

        return '$'.$snapshot['symbol'].' GEX levels | '.$session."\n"
            .'EOD '.substr($snapshot['data_date'], 0, 10)." | 2W scope\n"
            .'Total net GEX: '.SocialCard::exposure($p['total'])."\n"
            .'Positive peak: '.SocialCard::level($p['positive'])."\n"
            .'Negative peak: '.SocialCard::level($p['negative'])."\n"
            ."Explore levels and positioning: #GEX\n"
            .'https://gexoptions.com/?utm_source=x&utm_medium=social&utm_campaign=daily_gex&utm_content='.strtolower($snapshot['symbol']);
    }

    // Deliberately conservative: non-ASCII characters count as two, URLs as 23.
    // Overcounting joined emoji avoids accidentally accepting an over-limit post.
    public static function length(string $text): int
    {
        $urls = 0;
        $plain = preg_replace_callback('~https?://[^\s]+~u', function () use (&$urls) {
            $urls++;

            return '';
        }, $text);
        $length = $urls * 23;
        foreach (preg_split('//u', $plain, -1, PREG_SPLIT_NO_EMPTY) as $character) {
            $length += ord($character[0]) < 128 ? 1 : 2;
        }

        return $length;
    }

    public static function validate(string $body): void
    {
        if (trim($body) === '' || self::length($body) > 280) {
            throw ValidationException::withMessages(['body' => 'Use 1–280 weighted characters. Links count as 23 characters.']);
        }
    }
}
