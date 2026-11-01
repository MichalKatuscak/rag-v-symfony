<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\Metrics;
use App\Eval\Question;
use App\Eval\QuestionSet;
use App\Eval\Results;
use App\Ollama\OllamaClient;
use App\Search\Access;
use App\Search\Hit;
use App\Search\Search;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * E1–E3: které hledání najde správný úryvek. Každá odpověditelná otázka dvakrát (s diakritikou a bez),
 * sedm variant hledání, metriky hit@1, hit@5, recall@5, MRR s intervaly spolehlivosti.
 * Navíc rozložení skóre (top-1 kosinus a skóre rerankeru) pro odpověditelné vs. neodpověditelné otázky.
 */
#[AsCommand('app:mereni:hledani', 'Změří varianty hledání na sadě otázek (E1–E3).')]
final class MeasureSearchCommand
{
    private const PAIRS = [
        ['vektor', 'hybrid'],
        ['fulltext', 'fulltext_tvary'],
        ['vektor_bez_nadpisu', 'vektor'],
        ['hybrid_simple', 'hybrid'],
        ['hybrid', 'hybrid_rerank'],
        ['vektor', 'hybrid_rerank'],
    ];

    public function __construct(
        private readonly Search $search,
        private readonly QuestionSet $questions,
        private readonly OllamaClient $ollama,
        private readonly Results $results,
        private readonly string $embedModel,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Přeskočit variantu s rerankerem')] bool $bezRerankeru = false): int
    {
        $variants = array_values(array_filter(Search::VARIANTS, static fn (string $v): bool => !$bezRerankeru || 'hybrid_rerank' !== $v));
        $access = Access::zakaznik();

        $answerable = $this->questions->answerable();
        $runs = [];
        foreach ($answerable as $q) {
            $runs[] = ['q' => $q, 'diakritika' => true];
            $runs[] = ['q' => $q->withoutDiacritics(), 'diakritika' => false];
        }

        // Embedding dotazů zvlášť, ať latence variant neobsahuje volání Ollamy.
        $embedMs = [];
        foreach ($runs as $run) {
            $t = hrtime(true);
            $this->search->queryVector($run['q']->otazka);
            $embedMs[] = (hrtime(true) - $t) / 1e6;
        }

        $rows = [];
        $latency = [];
        $io->progressStart(\count($runs) * \count($variants));
        foreach ($runs as $run) {
            /** @var Question $q */
            $q = $run['q'];
            $detail = ['id' => $q->id, 'otazka' => $q->otazka, 'kategorie' => $q->kategorie, 'diakritika' => $run['diakritika'], 'spravne' => $q->spravne];
            foreach ($variants as $variant) {
                $t = hrtime(true);
                $hits = $this->search->search($q->otazka, $variant, $access, 10);
                $latency[$variant][] = (hrtime(true) - $t) / 1e6;
                $detail['varianty'][$variant] = Metrics::forQuestion($hits, $q->spravne) + [
                    'top5' => array_map(static fn (Hit $h): string => $h->sectionKey, \array_slice($hits, 0, 5)),
                ];
                $io->progressAdvance();
            }
            $rows[] = $detail;
        }
        $io->progressFinish();

        $summary = [];
        foreach ($variants as $variant) {
            $pick = static fn (callable $filter): array => array_map(static fn (array $r): array => $r['varianty'][$variant], array_values(array_filter($rows, $filter)));
            $summary[$variant] = [
                'vse' => Metrics::aggregate($pick(static fn (): bool => true)),
                's_diakritikou' => Metrics::aggregate($pick(static fn (array $r): bool => $r['diakritika'])),
                'bez_diakritiky' => Metrics::aggregate($pick(static fn (array $r): bool => !$r['diakritika'])),
                'podle_kategorie' => [],
                'latence_ms' => self::percentiles($latency[$variant]),
            ];
            foreach (array_unique(array_column($rows, 'kategorie')) as $cat) {
                $summary[$variant]['podle_kategorie'][$cat] = Metrics::aggregate($pick(static fn (array $r): bool => $r['kategorie'] === $cat && $r['diakritika']));
            }
        }

        // Párová srovnání (stejné otázky): rozdíl hit@5 a MRR s intervalem spolehlivosti.
        $pairs = [];
        foreach (self::PAIRS as [$a, $b]) {
            if (!\in_array($a, $variants, true) || !\in_array($b, $variants, true)) {
                continue;
            }
            foreach (['hit5', 'rr'] as $m) {
                $pairs["$a → $b"][$m] = Metrics::pairedDifference(
                    array_map(static fn (array $r) => $r['varianty'][$a][$m], $rows),
                    array_map(static fn (array $r) => $r['varianty'][$b][$m], $rows),
                );
            }
        }
        // Diakritika: stejná otázka s háčky vs. bez, pro každou variantu.
        $accentPairs = [];
        $with = array_values(array_filter($rows, static fn (array $r): bool => $r['diakritika']));
        $without = array_values(array_filter($rows, static fn (array $r): bool => !$r['diakritika']));
        foreach ($variants as $variant) {
            $accentPairs[$variant] = Metrics::pairedDifference(
                array_map(static fn (array $r) => $r['varianty'][$variant]['hit5'], $with),
                array_map(static fn (array $r) => $r['varianty'][$variant]['hit5'], $without),
            );
        }

        $scores = $this->scoreDistribution($io, $bezRerankeru);

        $path = $this->results->write('hledani', [
            'popis' => 'E1–E3: varianty hledání; každá odpověditelná otázka s diakritikou i bez; filtr = zákazník (veřejné podklady e-shopu a dodavatelů).',
            'embed_model' => $this->embedModel,
            'otazek' => \count($answerable),
            'embedding_dotazu_ms' => self::percentiles($embedMs),
            'varianty' => $summary,
            'parova_srovnani_hit5_a_mrr' => $pairs,
            'diakritika_rozdil_hit5' => $accentPairs,
            'skore' => $scores,
            'otazky' => $rows,
        ]);

        $io->table(['Varianta', 'hit@1', 'hit@5', 'MRR', 'hit@5 bez diakritiky', 'ms p50'], array_map(static fn (string $v): array => [
            $v, $summary[$v]['vse']['hit1'], $summary[$v]['vse']['hit5'], $summary[$v]['vse']['mrr'], $summary[$v]['bez_diakritiky']['hit5'], $summary[$v]['latence_ms']['p50'],
        ], $variants));
        $io->success('Uloženo do '.$path);

        return 0;
    }

    /**
     * E1 / práh „nevím“: dá se podle skóre nejlepšího úryvku poznat, že odpověď v podkladech není?
     *
     * @return array<string, mixed>
     */
    private function scoreDistribution(SymfonyStyle $io, bool $noReranker): array
    {
        $access = Access::zakaznik();
        $groups = ['odpovedi_v_podkladech' => [], 'neodpovezitelne' => []];
        foreach ($this->questions->all() as $q) {
            if ('neodpovezitelna' === $q->kategorie) {
                $group = 'neodpovezitelne';
            } elseif ($q->answerable()) {
                $group = 'odpovedi_v_podkladech';
            } else {
                continue;
            }
            $top = $this->search->search($q->otazka, 'vektor', $access, 1)[0] ?? null;
            $row = ['id' => $q->id, 'kosinus_top1' => $top ? round($top->score, 4) : null, 'top1_spravny' => $top && \in_array($top->sectionKey, $q->spravne, true)];
            if (!$noReranker) {
                $rr = $this->search->search($q->otazka, 'hybrid_rerank', $access, 1)[0] ?? null;
                $row['rerank_top1'] = $rr ? round($rr->score, 4) : null;
            }
            $groups[$group][] = $row;
        }

        $out = ['skupiny' => $groups];
        foreach (['kosinus_top1', 'rerank_top1'] as $metric) {
            if ($noReranker && 'rerank_top1' === $metric) {
                continue;
            }
            $yes = array_values(array_filter(array_column($groups['odpovedi_v_podkladech'], $metric), 'is_float'));
            $no = array_values(array_filter(array_column($groups['neodpovezitelne'], $metric), 'is_float'));
            $out[$metric] = [
                'odpovedi_v_podkladech' => self::stats($yes),
                'neodpovezitelne' => self::stats($no),
                'nejlepsi_prah' => self::bestThreshold($yes, $no),
            ];
        }

        return $out;
    }

    /**
     * Práh s nejvyšší vyváženou přesností a co by s ním chatbot udělal.
     *
     * @param list<float> $yes
     * @param list<float> $no
     */
    private static function bestThreshold(array $yes, array $no): ?array
    {
        if ([] === $yes || [] === $no) {
            return null;
        }
        $best = null;
        foreach (array_unique(array_merge($yes, $no)) as $t) {
            $tp = \count(array_filter($yes, static fn (float $s): bool => $s >= $t)) / \count($yes);
            $tn = \count(array_filter($no, static fn (float $s): bool => $s < $t)) / \count($no);
            $bal = ($tp + $tn) / 2;
            if (null === $best || $bal > $best['vyvazena_presnost']) {
                $best = ['prah' => $t, 'vyvazena_presnost' => round($bal, 4),
                    'odmitnute_odpovedi_v_podkladech' => \count($yes) - (int) round($tp * \count($yes)),
                    'propustene_neodpovezitelne' => \count($no) - (int) round($tn * \count($no))];
            }
        }

        return $best;
    }

    /** @param list<float> $v */
    private static function stats(array $v): ?array
    {
        if ([] === $v) {
            return null;
        }
        sort($v);

        return ['n' => \count($v), 'min' => $v[0], 'median' => $v[intdiv(\count($v), 2)], 'max' => end($v), 'hodnoty' => $v];
    }

    /** @param list<float> $ms */
    public static function percentiles(array $ms): array
    {
        sort($ms);
        $n = \count($ms);
        if (0 === $n) {
            return ['n' => 0, 'p50' => null, 'p95' => null];
        }

        return ['n' => $n, 'p50' => round($ms[(int) floor(0.5 * ($n - 1))], 2), 'p95' => round($ms[(int) floor(0.95 * ($n - 1))], 2)];
    }
}
