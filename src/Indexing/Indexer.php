<?php

declare(strict_types=1);

namespace App\Indexing;

use App\Ollama\OllamaClient;
use Doctrine\DBAL\Connection;

/**
 * Zaindexuje jeden dokument. Invariant: v indexu je pro každý dokument právě jedna verze.
 *
 * 1. Beze změny souboru i modelu → nic.
 * 2. Embedding jen pro úryvky, které jsou nové, změněné, nebo je počítal jiný model.
 * 3. V jedné transakci: upsert úryvků + smazání úryvků, které v nové verzi nejsou.
 */
final class Indexer
{
    public function __construct(
        private readonly Connection $db,
        private readonly OllamaClient $ollama,
        private readonly MarkdownChunker $chunker,
        private readonly string $embedModel,
    ) {
    }

    /** @return array{document: string, status: string, chunks: int, embedded: int, deleted: int, tokens: int} */
    public function index(SourceDocument $doc): array
    {
        $current = $this->db->fetchAssociative('SELECT content_hash, embedding_model FROM document WHERE id = ?', [$doc->id]);
        if (false !== $current && $current['content_hash'] === $doc->hash && $current['embedding_model'] === $this->embedModel) {
            return ['document' => $doc->id, 'status' => 'beze změny', 'chunks' => 0, 'embedded' => 0, 'deleted' => 0, 'tokens' => 0];
        }

        $chunks = $this->chunker->chunk($doc);
        $existing = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, content_hash, embedding_model FROM chunk WHERE document_id = ?', [$doc->id]) as $row) {
            $existing[$row['id']] = $row;
        }

        // Embeddingy mimo transakci (volání Ollamy může trvat), zápis pak atomicky.
        $vectors = [];
        $tokens = 0;
        foreach ($chunks as $chunk) {
            $old = $existing[$chunk->id] ?? null;
            if (null !== $old && $old['content_hash'] === $chunk->contentHash && $old['embedding_model'] === $this->embedModel) {
                continue;
            }
            $withTitle = $this->ollama->embed($this->embedModel, [$chunk->textWithTitle()]);
            $plain = $this->ollama->embed($this->embedModel, [$chunk->content]);
            $vectors[$chunk->id] = [$withTitle['vectors'][0], $plain['vectors'][0], $withTitle['prompt_tokens']];
            $tokens += $withTitle['prompt_tokens'];
        }

        $deleted = 0;
        $this->db->transactional(function (Connection $db) use ($doc, $chunks, $vectors, $existing, &$deleted): void {
            $db->executeStatement(<<<'SQL'
                INSERT INTO document (id, nazev, pristup, zdroj, platnost_od, content_hash, embedding_model, indexed_at)
                VALUES (:id, :nazev, :pristup, :zdroj, NULLIF(:platnost, '')::date, :hash, :model, now())
                ON CONFLICT (id) DO UPDATE SET nazev = EXCLUDED.nazev, pristup = EXCLUDED.pristup, zdroj = EXCLUDED.zdroj,
                    platnost_od = EXCLUDED.platnost_od, content_hash = EXCLUDED.content_hash,
                    embedding_model = EXCLUDED.embedding_model, indexed_at = now()
                SQL, ['id' => $doc->id, 'nazev' => $doc->nazev, 'pristup' => $doc->pristup, 'zdroj' => $doc->zdroj,
                    'platnost' => $doc->platnostOd, 'hash' => $doc->hash, 'model' => $this->embedModel]);

            foreach ($chunks as $chunk) {
                if (!isset($vectors[$chunk->id])) {
                    // Text beze změny; metadata dokumentu (oprávnění) se ale mohla změnit.
                    $db->executeStatement('UPDATE chunk SET pristup = ?, zdroj = ? WHERE id = ?', [$doc->pristup, $doc->zdroj, $chunk->id]);
                    continue;
                }
                [$withTitle, $plain, $chunkTokens] = $vectors[$chunk->id];
                $db->executeStatement(<<<'SQL'
                    INSERT INTO chunk (id, document_id, section_key, part, title_path, content, content_hash, pristup, zdroj,
                                       embedding_model, tokens, embedding, embedding_plain)
                    VALUES (:id, :doc, :key, :part, :path, :content, :hash, :pristup, :zdroj, :model, :tokens, :emb, :plain)
                    ON CONFLICT (id) DO UPDATE SET section_key = EXCLUDED.section_key, part = EXCLUDED.part,
                        title_path = EXCLUDED.title_path, content = EXCLUDED.content, content_hash = EXCLUDED.content_hash,
                        pristup = EXCLUDED.pristup, zdroj = EXCLUDED.zdroj, embedding_model = EXCLUDED.embedding_model,
                        tokens = EXCLUDED.tokens, embedding = EXCLUDED.embedding, embedding_plain = EXCLUDED.embedding_plain
                    SQL, ['id' => $chunk->id, 'doc' => $chunk->documentId, 'key' => $chunk->sectionKey, 'part' => $chunk->part,
                        'path' => $chunk->titlePath, 'content' => $chunk->content, 'hash' => $chunk->contentHash,
                        'pristup' => $doc->pristup, 'zdroj' => $doc->zdroj, 'model' => $this->embedModel, 'tokens' => $chunkTokens,
                        'emb' => Vector::literal($withTitle), 'plain' => Vector::literal($plain)]);
            }

            $keep = array_map(static fn (Chunk $c): string => $c->id, $chunks);
            $stale = array_values(array_diff(array_keys($existing), $keep));
            if ([] !== $stale) {
                $deleted = $db->executeStatement('DELETE FROM chunk WHERE id IN (?)', [$stale], [\Doctrine\DBAL\ArrayParameterType::STRING]);
            }
        });

        return ['document' => $doc->id, 'status' => false === $current ? 'nový' : 'změněný', 'chunks' => \count($chunks),
            'embedded' => \count($vectors), 'deleted' => (int) $deleted, 'tokens' => $tokens];
    }

    public function remove(string $documentId): int
    {
        // Výmaz (např. GDPR): s dokumentem zmizí i úryvky a jejich vektory (ON DELETE CASCADE).
        return $this->db->executeStatement('DELETE FROM document WHERE id = ?', [$documentId]);
    }
}
