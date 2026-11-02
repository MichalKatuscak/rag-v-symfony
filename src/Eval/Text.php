<?php

declare(strict_types=1);

namespace App\Eval;

final class Text
{
    public static function stripDiacritics(string $s): string
    {
        $t = \Normalizer::normalize($s, \Normalizer::FORM_D);

        return (string) preg_replace('/\p{Mn}+/u', '', (string) $t);
    }

    /** Pro porovnání faktů: malá písmena, bez diakritiky, sjednocené mezery v číslech. */
    public static function forMatch(string $s): string
    {
        $s = mb_strtolower(self::stripDiacritics($s));
        // Mezery mezi číslicemi pryč: „2 000“ = „2000“, „+420 500 600 700“ obsahuje „500 600 700“.
        $s = preg_replace('/(?<=\d)[ \x{00A0}](?=\d)/u', '', $s);
        $s = str_replace(',', '.', $s);

        return (string) preg_replace('/\s+/u', ' ', $s);
    }

    /** Fakto „a|b“: stačí kterákoli alternativa. Porovnání bez ohledu na velikost písmen a diakritiku. */
    public static function containsFact(string $text, string $fact): bool
    {
        foreach (explode('|', $fact) as $alternative) {
            if (str_contains(self::forMatch($text), self::forMatch(trim($alternative)))) {
                return true;
            }
        }

        return false;
    }
}
