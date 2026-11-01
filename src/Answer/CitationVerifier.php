<?php

declare(strict_types=1);

namespace App\Answer;

use App\Search\Hit;

/**
 * Deterministická kontrola citací, bez dalšího modelu:
 * 1. citovaný úsek existuje (model ho dostal v kontextu),
 * 2. citát je v úryvku doslova (po sjednocení mezer a uvozovek),
 * 3. každé číslo z odpovědi je v některém citovaném úryvku (chytí vymyšlené i zastaralé ceny a lhůty).
 *
 * Nechytí odpověď, která cituje správný úryvek, ale vyvodí z něj nesmysl bez čísla.
 */
final class CitationVerifier
{
    /**
     * @param array{nevim?: bool, odpoved: string, citace?: list<array{usek?: string, citat?: string}>} $answer
     * @param array<string, Hit> $labels
     *
     * @return array{neplatne_useky: list<string>, citaty_nenalezene: list<string>, cisla_mimo_citace: list<string>, bez_citace: bool, v_poradku: bool}
     */
    public function verify(array $answer, array $labels): array
    {
        $invalid = [];
        $notFound = [];
        $citedTexts = [];
        foreach ($answer['citace'] ?? [] as $c) {
            $label = strtoupper(trim((string) ($c['usek'] ?? ''), " []"));
            if (!isset($labels[$label])) {
                $invalid[] = $label;
                continue;
            }
            $text = $labels[$label]->titlePath."\n".$labels[$label]->content;
            $citedTexts[] = $text;
            $quote = self::normalize((string) ($c['citat'] ?? ''));
            if ('' === $quote || !str_contains(self::normalize($text), $quote)) {
                $notFound[] = $label.': '.mb_substr((string) ($c['citat'] ?? ''), 0, 120);
            }
        }

        $numbersOutside = [];
        if (!($answer['nevim'] ?? false)) {
            $cited = self::numbers(implode("\n", $citedTexts));
            foreach (self::numbers($answer['odpoved']) as $n) {
                if (!\in_array($n, $cited, true)) {
                    $numbersOutside[] = $n;
                }
            }
        }

        $noCitation = !($answer['nevim'] ?? false) && [] === ($answer['citace'] ?? []);

        return [
            'neplatne_useky' => $invalid,
            'citaty_nenalezene' => $notFound,
            'cisla_mimo_citace' => array_values(array_unique($numbersOutside)),
            'bez_citace' => $noCitation,
            'v_poradku' => [] === $invalid && [] === $notFound && [] === $numbersOutside && !$noCitation,
        ];
    }

    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = strtr($s, ['„' => '"', '“' => '"', '”' => '"', '‚' => "'", '‘' => "'", '’' => "'", '–' => '-', '—' => '-', "\u{00A0}" => ' ']);
        $s = preg_replace('/\s+/u', ' ', $s);

        return trim($s, " .…\"'");
    }

    /**
     * Čísla v textu, sjednocená: „2 000“ = „2000“, „7,90“ = „7.90“.
     *
     * @return list<string>
     */
    public static function numbers(string $s): array
    {
        preg_match_all('/\d+(?:[ \x{00A0}]\d{3})*(?:[.,]\d+)?/u', $s, $m);

        return array_values(array_unique(array_map(static fn (string $n): string => str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $n), $m[0])));
    }
}
