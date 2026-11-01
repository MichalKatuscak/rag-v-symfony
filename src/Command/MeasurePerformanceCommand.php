<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\QuestionSet;
use App\Eval\Results;
use App\Ollama\OllamaClient;
use App\Search\Search;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * E8: výkon.
 * (a) Přesné hledání (bez indexu) vs. HNSW na 10k a 100k syntetických vektorech (1024 dim). Vektory vznikají
 *     ze skutečných embeddingů úryvků + šum, dotazy jsou skutečné otázky ze sady. Recall@10 HNSW proti přesnému.
 * (b) HNSW + filtr, který propustí ~10 % řádků: kolik řádků vrátí bez a s iterative index scan (pgvector 0.8+).
 * (c) Výměna modelů v paměti GPU: embed → chat → embed → chat, load_duration z Ollamy.
 */
#[AsCommand('app:mereni:vykon', 'Změří přesné hledání vs. HNSW, filtr s HNSW a výměnu modelů (E8).')]
final class MeasurePerformanceCommand
{
    public function __construct(
        private readonly Connection $db,
        private readonly OllamaClient $ollama,
        private readonly Search $search,
        private readonly QuestionSet $questions,
        private readonly Results $results,
        private readonly string $embedModel,
        private readonly array $chatModels,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Velikosti syntetických tabulek, čárkou')] string $velikosti = '10000,100000',
        #[Option('Které chat modely použít pro výměnu modelů (maly,velky)')] string $modely = 'maly,velky',
        #[Option('Přeskočit část s pgvectorem')] bool $bezDatabaze = false,
    ): int {
        $out = [];
        if (!$bezDatabaze) {
            $queries = array_map(fn ($q): string => $this->search->queryVector($q->otazka), \array_slice($this->questions->answerable(), 0, 50));
            foreach (array_map('intval', explode(',', $velikosti)) as $n) {
                $out['pgvector'][(string) $n] = $this->vectors($io, $n, $queries);
            }
            $out['pgvector_verze'] = $this->db->fetchOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        }
        $out['vymena_modelu'] = $this->modelSwap($io, array_values(array_filter(explode(',', $modely))));

        $path = $this->results->write('vykon', ['popis' => 'E8: přesné hledání vs. HNSW, HNSW s filtrem, výměna modelů v paměti.'] + $out);
        $io->success('Uloženo do '.$path);

        return 0;
    }

    /** @param list<string> $queries */
    private function vectors(SymfonyStyle $io, int $n, array $queries): array
    {
        $table = 'synth_'.$n;
        $io->section("$n vektorů");
        $this->db->executeStatement("DROP TABLE IF EXISTS $table");
        $this->db->executeStatement("CREATE TABLE $table (id int PRIMARY KEY, kategorie int NOT NULL, embedding vector(1024) NOT NULL)");
        $t = hrtime(true);
        // Každý řádek = náhodný skutečný úryvek + rovnoměrný šum ±0,02 v každé složce. Kategorie 0–9 → filtr propustí ~10 %.
        $this->db->executeStatement(<<<SQL
            INSERT INTO $table (id, kategorie, embedding)
            SELECT g, g % 10, (SELECT array_agg(x + (random() - 0.5) * 0.04 ORDER BY i)
                               FROM unnest(b.embedding::real[]) WITH ORDINALITY AS u(x, i))::vector
            FROM generate_series(1, :n) AS g
            CROSS JOIN LATERAL (SELECT embedding FROM chunk ORDER BY random() + g * 0 LIMIT 1) AS b
            SQL, ['n' => $n]);
        $this->db->executeStatement("ANALYZE $table");
        $generated = (hrtime(true) - $t) / 1e9;

        $exact = $this->run($table, $queries, false);
        $t = hrtime(true);
        $this->db->executeStatement("CREATE INDEX {$table}_hnsw ON $table USING hnsw (embedding vector_cosine_ops)");
        $build = (hrtime(true) - $t) / 1e9;
        $hnsw = $this->run($table, $queries, false);
        // Syntetická data = kopie 284 skutečných úryvků se šumem, takže mezi sousedy je hodně skoro shodných vzdáleností
        // a recall HNSW vychází hůř než na skutečných datech. Proto i vyšší ef_search (výchozí je 40).
        $efSweep = [];
        foreach ([40, 100, 200] as $ef) {
            $efSweep[$ef] = 40 === $ef ? $hnsw : $this->run($table, $queries, false, 'auto', $ef);
        }

        $exactFiltered = $this->run($table, $queries, true, 'exact');
        $hnswFilteredOff = $this->run($table, $queries, true, 'off');
        $hnswFilteredRelaxed = $this->run($table, $queries, true, 'relaxed_order');

        $recall = static fn (array $a, array $b): float => round(array_sum(array_map(static fn ($x, $y): float => \count(array_intersect($x, $y)) / max(1, \count($x)), $a['ids'], $b['ids'])) / \count($a['ids']), 4);
        $size = $this->db->fetchOne("SELECT pg_size_pretty(pg_total_relation_size('$table'))");
        $result = [
            'generovani_s' => round($generated, 1),
            'velikost_tabulky' => $size,
            'presne_bez_indexu_ms' => $exact['ms'],
            'hnsw_stavba_s' => round($build, 1),
            'hnsw_ms' => $hnsw['ms'],
            'hnsw_recall_at_10' => $recall($exact, $hnsw),
            'hnsw_podle_ef_search' => array_map(static fn (array $r): array => ['ms' => $r['ms'], 'recall_at_10' => $recall($exact, $r)], $efSweep),
            'filtr_10_procent' => [
                'presne_ms' => $exactFiltered['ms'],
                'hnsw_bez_iterative_scan' => ['ms' => $hnswFilteredOff['ms'], 'prumer_vracenych_radku' => $hnswFilteredOff['rows_avg'], 'recall_at_10' => $recall($exactFiltered, $hnswFilteredOff)],
                'hnsw_iterative_scan_relaxed' => ['ms' => $hnswFilteredRelaxed['ms'], 'prumer_vracenych_radku' => $hnswFilteredRelaxed['rows_avg'], 'recall_at_10' => $recall($exactFiltered, $hnswFilteredRelaxed)],
            ],
        ];
        $io->writeln(json_encode($result, \JSON_UNESCAPED_UNICODE));
        $this->db->executeStatement("DROP TABLE $table");

        return $result;
    }

    /** @param list<string> $queries */
    private function run(string $table, array $queries, bool $filtered, string $mode = 'auto', int $efSearch = 40): array
    {
        $ids = [];
        $ms = [];
        $rows = [];
        $this->db->transactional(function (Connection $db) use ($table, $queries, $filtered, $mode, $efSearch, &$ids, &$ms, &$rows): void {
            $db->executeStatement('SET LOCAL hnsw.ef_search = '.$efSearch);
            if ('exact' === $mode) {
                $db->executeStatement('SET LOCAL enable_indexscan = off');
            } elseif ('auto' !== $mode) {
                $db->executeStatement("SET LOCAL hnsw.iterative_scan = $mode");
                $db->executeStatement('SET LOCAL enable_seqscan = off'); // ať plánovač opravdu použije HNSW
            }
            $where = $filtered ? 'WHERE kategorie = 3' : '';
            foreach ($queries as $q) {
                $t = hrtime(true);
                $found = $db->fetchFirstColumn("SELECT id FROM $table $where ORDER BY embedding <=> :q LIMIT 10", ['q' => $q]);
                $ms[] = (hrtime(true) - $t) / 1e6;
                $ids[] = $found;
                $rows[] = \count($found);
            }
        });

        return ['ids' => $ids, 'ms' => MeasureSearchCommand::percentiles($ms), 'rows_avg' => round(array_sum($rows) / max(1, \count($rows)), 2)];
    }

    /** @param list<string> $models */
    private function modelSwap(SymfonyStyle $io, array $models): array
    {
        $io->section('Výměna modelů');
        $out = [];
        foreach ($models as $key) {
            $chat = $this->chatModels[$key] ?? $key;
            $this->ollama->unload($chat);
            $this->ollama->unload($this->embedModel, true);
            $steps = [];
            foreach (['embed', 'chat', 'embed', 'chat', 'embed'] as $step) {
                $r = 'embed' === $step
                    ? $this->ollama->embed($this->embedModel, ['Za jak dlouho odešlete pákový kávovar?'])
                    : $this->ollama->chat($chat, [['role' => 'user', 'content' => 'Odpověz jedním slovem: jakou barvu má nebe?']], ['num_ctx' => 8192]);
                $steps[] = ['krok' => $step, 'load_ms' => $r['load_ms'], 'wall_ms' => $r['wall_ms'],
                    'v_pameti' => array_map(static fn (array $m): array => ['model' => $m['name'], 'size_vram_mb' => (int) round(($m['size_vram'] ?? 0) / 1048576), 'size_mb' => (int) round(($m['size'] ?? 0) / 1048576)], $this->ollama->ps())];
            }
            $out[$chat] = $steps;
            $io->writeln($chat.': '.implode(' → ', array_map(static fn ($s) => $s['krok'].' '.$s['load_ms'].' ms', $steps)));
        }

        return $out;
    }
}
