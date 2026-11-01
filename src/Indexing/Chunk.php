<?php

declare(strict_types=1);

namespace App\Indexing;

final readonly class Chunk
{
    public function __construct(
        public string $id,
        public string $documentId,
        public string $sectionKey,
        public int $part,
        /** „Název dokumentu › Sekce › Podsekce“ */
        public string $titlePath,
        public string $content,
        public string $contentHash,
    ) {
    }

    /** Text pro embedding s předřazenou cestou nadpisů (levná obdoba „contextual retrieval“). */
    public function textWithTitle(): string
    {
        return $this->titlePath."\n\n".$this->content;
    }
}
