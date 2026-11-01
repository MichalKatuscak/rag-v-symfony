<?php

declare(strict_types=1);

namespace App\Indexing;

use Doctrine\DBAL\Connection;

/** Tabulky pro podklady. Záměrně čisté SQL: v článku je vidět každý sloupec i index. */
final class Schema
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(bool $drop = false): void
    {
        if ($drop) {
            $this->db->executeStatement('DROP TABLE IF EXISTS chunk, document, messenger_messages CASCADE');
        }
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document (
                id text PRIMARY KEY,
                nazev text NOT NULL,
                pristup text NOT NULL,          -- verejne | interni
                zdroj text NOT NULL,            -- eshop | zakaznik | dodavatel
                platnost_od date,
                content_hash text NOT NULL,     -- hash souboru: beze změny = nic nepřepočítávat
                embedding_model text NOT NULL,
                indexed_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->db->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chunk (
                id uuid PRIMARY KEY,            -- UUID v5 z (dokument, sekce, část): stejné při přeindexování
                document_id text NOT NULL REFERENCES document(id) ON DELETE CASCADE,
                section_key text NOT NULL,      -- např. doprava#kavovary
                part int NOT NULL,
                title_path text NOT NULL,       -- Doprava a dodací lhůty › Kávovary
                content text NOT NULL,
                content_hash text NOT NULL,
                pristup text NOT NULL,          -- kopie z dokumentu, aby filtr oprávnění byl ve WHERE bez JOINu
                zdroj text NOT NULL,
                embedding_model text NOT NULL,  -- vektory různých modelů nejsou porovnatelné
                tokens int NOT NULL,            -- počet tokenů textu s nadpisem podle Ollamy
                embedding vector(1024) NOT NULL,        -- bge-m3: cesta nadpisů + text
                embedding_plain vector(1024) NOT NULL,  -- bge-m3: jen text (pro srovnání)
                tsv_simple tsvector GENERATED ALWAYS AS (to_tsvector('public.cs_simple', title_path || ' ' || content)) STORED,
                tsv_lemma tsvector GENERATED ALWAYS AS (public.cs_lemma_vector(title_path || ' ' || content)) STORED
            )
            SQL);
        $this->db->executeStatement('CREATE INDEX IF NOT EXISTS chunk_embedding_hnsw ON chunk USING hnsw (embedding vector_cosine_ops)');
        $this->db->executeStatement('CREATE INDEX IF NOT EXISTS chunk_tsv_simple ON chunk USING gin (tsv_simple)');
        $this->db->executeStatement('CREATE INDEX IF NOT EXISTS chunk_tsv_lemma ON chunk USING gin (tsv_lemma)');
        $this->db->executeStatement('CREATE INDEX IF NOT EXISTS chunk_document ON chunk (document_id)');
    }
}
