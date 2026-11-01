<?php

declare(strict_types=1);

namespace App\Search;

use App\Indexing\Vector;
use App\Ollama\OllamaClient;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Varianty hledání, které repo porovnává. Všechny jsou jeden SQL dotaz nad pgvectorem a fulltextem Postgresu.
 *
 * - vektor              kosinová vzdálenost, embedding „cesta nadpisů + text“
 * - vektor_bez_nadpisu  totéž, embedding jen z textu úryvku
 * - fulltext            cs_simple: malá písmena, bez diakritiky, bez stop slov, žádné tvary slov
 * - fulltext_tvary      cs_lemma: český Hunspell (základní tvar), pak bez diakritiky
 * - hybrid              RRF (k = 60) z „vektor“ a „fulltext_tvary“
 * - hybrid_simple       RRF z „vektor“ a „fulltext“
 * - hybrid_rerank       hybrid → 30 kandidátů → bge-reranker-v2-m3
 */
final class Search
{
    public const VARIANTS = ['vektor', 'vektor_bez_nadpisu', 'fulltext', 'fulltext_tvary', 'hybrid', 'hybrid_simple', 'hybrid_rerank'];

    private const RRF_K = 60;
    private const CANDIDATES = 40;
    private const RERANK_CANDIDATES = 30;

    /** @var array<string, string> */
    private array $queryVectors = [];

    public function __construct(
        private readonly Connection $db,
        private readonly OllamaClient $ollama,
        private readonly Reranker $reranker,
        private readonly string $embedModel,
    ) {
    }

    /** @return list<Hit> */
    public function search(string $query, string $variant, Access $access, int $limit = 5): array
    {
        return match ($variant) {
            'vektor' => $this->vector($query, 'embedding', $access, $limit),
            'vektor_bez_nadpisu' => $this->vector($query, 'embedding_plain', $access, $limit),
            'fulltext' => $this->fulltext($query, 'tsv_simple', 'public.cs_simple_query', $access, $limit),
            'fulltext_tvary' => $this->fulltext($query, 'tsv_lemma', 'public.cs_lemma_query', $access, $limit),
            'hybrid' => $this->hybrid($query, 'tsv_lemma', 'public.cs_lemma_query', $access, $limit),
            'hybrid_simple' => $this->hybrid($query, 'tsv_simple', 'public.cs_simple_query', $access, $limit),
            'hybrid_rerank' => $this->rerank($query, $this->hybrid($query, 'tsv_lemma', 'public.cs_lemma_query', $access, self::RERANK_CANDIDATES), $limit),
            default => throw new \InvalidArgumentException('Neznámá varianta '.$variant),
        };
    }

    /** Embedding dotazu (cache v rámci běhu, ať se pro každou variantu nepočítá znovu). */
    public function queryVector(string $query): string
    {
        return $this->queryVectors[$query] ??= Vector::literal($this->ollama->embed($this->embedModel, [$query])['vectors'][0]);
    }

    /** @return list<Hit> */
    private function vector(string $query, string $column, Access $access, int $limit): array
    {
        // Skóre = kosinová podobnost (1 − kosinová vzdálenost operátoru <=>).
        return $this->hits($this->db->fetchAllAssociative(<<<SQL
            SELECT id, document_id, section_key, title_path, content, pristup, zdroj, 1 - ($column <=> :q) AS score
            FROM chunk
            WHERE pristup IN (:pristup) AND zdroj IN (:zdroj)
            ORDER BY $column <=> :q
            LIMIT :limit
            SQL, $this->params($access, ['q' => $this->queryVector($query), 'limit' => $limit]), $this->types()));
    }

    /** @return list<Hit> */
    private function fulltext(string $query, string $column, string $toQuery, Access $access, int $limit): array
    {
        return $this->hits($this->db->fetchAllAssociative(<<<SQL
            SELECT id, document_id, section_key, title_path, content, pristup, zdroj, ts_rank($column, q) AS score
            FROM chunk, $toQuery(:t) AS q
            WHERE $column @@ q AND pristup IN (:pristup) AND zdroj IN (:zdroj)
            ORDER BY score DESC, id
            LIMIT :limit
            SQL, $this->params($access, ['t' => $query, 'limit' => $limit]), $this->types()));
    }

    /**
     * Reciprocal Rank Fusion (Cormack, Clarke, Büttcher 2009): skóre = Σ 1 / (k + pořadí).
     * Nesčítá nesouměřitelná skóre (kosinus vs. ts_rank), jen pořadí. Filtr oprávnění je v obou větvích.
     *
     * @return list<Hit>
     */
    private function hybrid(string $query, string $column, string $toQuery, Access $access, int $limit): array
    {
        return $this->hits($this->db->fetchAllAssociative(<<<SQL
            WITH v AS (
                SELECT id, row_number() OVER (ORDER BY embedding <=> :q) AS r
                FROM chunk
                WHERE pristup IN (:pristup) AND zdroj IN (:zdroj)
                ORDER BY embedding <=> :q
                LIMIT :candidates
            ), f AS (
                SELECT id, row_number() OVER (ORDER BY ts_rank($column, q) DESC, id) AS r
                FROM chunk, $toQuery(:t) AS q
                WHERE $column @@ q AND pristup IN (:pristup) AND zdroj IN (:zdroj)
                ORDER BY ts_rank($column, q) DESC, id
                LIMIT :candidates
            )
            SELECT c.id, c.document_id, c.section_key, c.title_path, c.content, c.pristup, c.zdroj,
                   COALESCE(1.0 / (:k + v.r), 0) + COALESCE(1.0 / (:k + f.r), 0) AS score
            FROM v FULL OUTER JOIN f USING (id)
            JOIN chunk c USING (id)
            ORDER BY score DESC, c.id
            LIMIT :limit
            SQL, $this->params($access, ['q' => $this->queryVector($query), 't' => $query, 'k' => self::RRF_K,
                'candidates' => self::CANDIDATES, 'limit' => $limit]), $this->types()));
    }

    /**
     * @param list<Hit> $candidates
     *
     * @return list<Hit>
     */
    private function rerank(string $query, array $candidates, int $limit): array
    {
        if ([] === $candidates) {
            return [];
        }
        $scores = $this->reranker->score($query, array_map(static fn (Hit $h): string => $h->titlePath."\n".$h->content, $candidates));
        $order = array_keys($candidates);
        usort($order, static fn (int $a, int $b): int => $scores[$b] <=> $scores[$a] ?: $a <=> $b);
        $hits = [];
        foreach (\array_slice($order, 0, $limit) as $rank => $i) {
            $h = $candidates[$i];
            $hits[] = new Hit($h->id, $h->documentId, $h->sectionKey, $h->titlePath, $h->content, $h->pristup, $h->zdroj, $scores[$i], $rank + 1);
        }

        return $hits;
    }

    /** @return list<Hit> */
    private function hits(array $rows): array
    {
        $hits = [];
        foreach ($rows as $i => $r) {
            $hits[] = new Hit($r['id'], $r['document_id'], $r['section_key'], $r['title_path'], $r['content'], $r['pristup'], $r['zdroj'], (float) $r['score'], $i + 1);
        }

        return $hits;
    }

    private function params(Access $access, array $extra): array
    {
        return ['pristup' => $access->pristup, 'zdroj' => $access->zdroj] + $extra;
    }

    private function types(): array
    {
        return ['pristup' => ArrayParameterType::STRING, 'zdroj' => ArrayParameterType::STRING];
    }
}
