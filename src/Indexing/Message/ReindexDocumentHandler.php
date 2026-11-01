<?php

declare(strict_types=1);

namespace App\Indexing\Message;

use App\Indexing\Indexer;
use App\Indexing\SourceDocument;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Idempotentní: stejná zpráva zpracovaná dvakrát (retry, duplicita) nic nezdvojí,
 * protože úryvky mají deterministická ID a nezměněný dokument se přeskočí podle hashe.
 * Když Ollama neodpoví, výjimka vrátí zprávu do fronty a Messenger ji zkusí znovu.
 */
#[AsMessageHandler]
final class ReindexDocumentHandler
{
    public function __construct(private readonly Indexer $indexer, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ReindexDocument $message): void
    {
        $result = $this->indexer->index(SourceDocument::fromFile($message->path));
        $this->logger->info('Přeindexováno {document}: {status}, úryvků {chunks}, embeddingů {embedded}, smazáno {deleted}', $result);
    }
}
