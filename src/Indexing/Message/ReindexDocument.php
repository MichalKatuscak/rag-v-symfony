<?php

declare(strict_types=1);

namespace App\Indexing\Message;

/** Dokument se změnil (nebo vznikl) → přepočítat jeho úryvky. Zpracuje worker Messengeru. */
final readonly class ReindexDocument
{
    public function __construct(public string $path)
    {
    }
}
