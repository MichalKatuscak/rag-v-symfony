<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * bge-reranker-v2-m3 přes llama.cpp server (/v1/rerank). Ollama endpoint pro reranking nemá
 * (issue ollama/ollama#3368 je otevřené od března 2024).
 */
final class Reranker
{
    public function __construct(private readonly HttpClientInterface $http, private readonly string $rerankerUrl)
    {
    }

    /**
     * @param list<string> $documents
     *
     * @return array<int, float> skóre podle indexu dokumentu
     */
    public function score(string $query, array $documents): array
    {
        $data = $this->http->request('POST', rtrim($this->rerankerUrl, '/').'/v1/rerank', [
            'json' => ['query' => $query, 'documents' => $documents, 'top_n' => \count($documents)],
            'timeout' => 300,
        ])->toArray();
        $scores = [];
        foreach ($data['results'] as $r) {
            $scores[$r['index']] = (float) $r['relevance_score'];
        }

        return $scores;
    }
}
