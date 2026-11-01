<?php

declare(strict_types=1);

namespace App\Command;

use App\Indexing\MarkdownChunker;
use App\Indexing\SourceDocument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:sekce', 'Vypíše klíče sekcí (pro sadu otázek) a velikosti úryvků. Nepotřebuje databázi ani Ollamu.')]
final class SectionsCommand
{
    public function __construct(private readonly MarkdownChunker $chunker, private readonly string $podkladyDir)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option] ?string $dir = null): int
    {
        $rows = [];
        $chars = [];
        foreach (glob(($dir ?? $this->podkladyDir).'/*.md') as $file) {
            $doc = SourceDocument::fromFile($file);
            foreach ($this->chunker->chunk($doc) as $c) {
                $rows[] = [$c->sectionKey.($c->part > 0 ? ' (část '.($c->part + 1).')' : ''), $doc->pristup, $doc->zdroj, mb_strlen($c->content), $c->titlePath];
                $chars[] = mb_strlen($c->content);
            }
        }
        $io->table(['Sekce', 'Přístup', 'Zdroj', 'Znaků', 'Cesta'], $rows);
        sort($chars);
        $io->writeln(\sprintf('Úryvků: %d, znaků: medián %d, max %d', \count($chars), $chars[intdiv(\count($chars), 2)] ?? 0, end($chars) ?: 0));

        return 0;
    }
}
