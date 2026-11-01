<?php

declare(strict_types=1);

namespace App\Eval;

use App\Search\Hit;

/** Metriky hledání nad sadou otázek + 95% interval spolehlivosti bootstrapem (pevný seed). */
final class Metrics
{
    /**
     * @param list<Hit>    $hits
     * @param list<string> $gold
     *
     * @return array{prvni_spravny: ?int, hit1: int, hit5: int, recall5: float, rr: float}
     */
    public static function forQuestion(array $hits, array $gold): array
    {
        $first = null;
        $found = [];
        foreach ($hits as $h) {
            if (\in_array($h->sectionKey, $gold, true)) {
                $first ??= $h->rank;
                if ($h->rank <= 5) {
                    $found[$h->sectionKey] = true;
                }
            }
        }

        return [
            'prvni_spravny' => $first,
            'hit1' => 1 === $first ? 1 : 0,
            'hit5' => null !== $first && $first <= 5 ? 1 : 0,
            'recall5' => \count($found) / max(1, \count($gold)),
            'rr' => null !== $first ? 1 / $first : 0.0,
        ];
    }

    /**
     * @param list<array{hit1: int, hit5: int, recall5: float, rr: float}> $rows
     *
     * @return array<string, mixed>
     */
    public static function aggregate(array $rows): array
    {
        $n = \count($rows);
        $out = ['n' => $n];
        foreach (['hit1', 'hit5', 'recall5', 'rr'] as $m) {
            $values = array_column($rows, $m);
            $name = 'rr' === $m ? 'mrr' : $m;
            $out[$name] = $n > 0 ? round(array_sum($values) / $n, 4) : null;
            $out[$name.'_ci95'] = self::bootstrap($values);
        }

        return $out;
    }

    /**
     * Párový bootstrap rozdílu průměrů (B − A) na stejných otázkách.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     *
     * @return array{rozdil: float, ci95: array{0: float, 1: float}}
     */
    public static function pairedDifference(array $a, array $b, int $samples = 5000): array
    {
        $n = \count($a);
        $diff = array_map(static fn ($x, $y): float => (float) $y - (float) $x, $a, $b);
        $ci = self::bootstrap($diff, $samples);

        return ['rozdil' => $n > 0 ? round(array_sum($diff) / $n, 4) : 0.0, 'ci95' => $ci];
    }

    /**
     * @param list<float|int> $values
     *
     * @return array{0: float, 1: float}|null
     */
    public static function bootstrap(array $values, int $samples = 5000): ?array
    {
        $n = \count($values);
        if ($n < 2) {
            return null;
        }
        mt_srand(42);
        $means = [];
        for ($s = 0; $s < $samples; ++$s) {
            $sum = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $sum += $values[mt_rand(0, $n - 1)];
            }
            $means[] = $sum / $n;
        }
        sort($means);

        return [round($means[(int) floor(0.025 * $samples)], 4), round($means[(int) floor(0.975 * $samples) - 1], 4)];
    }
}
