<?php

declare(strict_types=1);

namespace App\Search;

final readonly class Hit
{
    public function __construct(
        public string $id,
        public string $documentId,
        public string $sectionKey,
        public string $titlePath,
        public string $content,
        public string $pristup,
        public string $zdroj,
        public float $score,
        public int $rank,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(bool $withContent = false): array
    {
        $a = ['rank' => $this->rank, 'section' => $this->sectionKey, 'score' => round($this->score, 4), 'pristup' => $this->pristup, 'zdroj' => $this->zdroj];

        return $withContent ? $a + ['title' => $this->titlePath, 'content' => $this->content] : $a;
    }
}
