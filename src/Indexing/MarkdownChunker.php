<?php

declare(strict_types=1);

namespace App\Indexing;

use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Dělí dokument podle struktury: jeden úryvek = jedna sekce (## nebo ###).
 * Sekce delší než MAX_CHARS se rozdělí po odstavcích (bez překryvu).
 *
 * ID úryvku je deterministické (UUID v5 z dokumentu, sekce a pořadí části), takže
 * stejná sekce dostane při přeindexování stejné ID a upsert ji přepíše, místo aby
 * vedle staré verze vznikla nová.
 */
final class MarkdownChunker
{
    /** Zhruba 400 tokenů bge-m3 u české prózy; přesný počet tokenů se ukládá při embeddingu. */
    public const MAX_CHARS = 1600;

    private const NAMESPACE = 'b5f3c1de-6a0e-4c47-9a53-2f6c3f0d1a26';

    private readonly AsciiSlugger $slugger;

    public function __construct()
    {
        $this->slugger = new AsciiSlugger('cs');
    }

    /** @return list<Chunk> */
    public function chunk(SourceDocument $doc): array
    {
        $chunks = [];
        $h2 = null;
        $current = null; // [heading, path, lines]
        $flush = function () use (&$current, &$chunks, $doc): void {
            if (null === $current) {
                return;
            }
            $text = trim(implode("\n", $current['lines']));
            if ('' !== $text) {
                foreach ($this->split($text) as $part => $piece) {
                    $key = $doc->id.'#'.$this->slug($current['heading']);
                    $chunks[] = new Chunk(
                        Uuid::v5(Uuid::fromString(self::NAMESPACE), $key.'#'.$part)->toRfc4122(),
                        $doc->id,
                        $key,
                        $part,
                        $current['path'],
                        $piece,
                        hash('sha256', $current['path']."\n".$piece),
                    );
                }
            }
            $current = null;
        };

        foreach (explode("\n", $doc->body) as $line) {
            if (preg_match('/^(#{2,3}) (.+)$/', $line, $m)) {
                $flush();
                $heading = trim($m[2]);
                if ('##' === $m[1]) {
                    $h2 = $heading;
                    $path = $doc->nazev.' › '.$heading;
                } else {
                    $path = $doc->nazev.' › '.($h2 ?? '').' › '.$heading;
                }
                $current = ['heading' => $heading, 'path' => $path, 'lines' => []];
                continue;
            }
            if (null !== $current) {
                $current['lines'][] = $line;
            }
            // Text před první sekcí (# nadpis a poznámka o fiktivním e-shopu) se neindexuje.
        }
        $flush();

        return $chunks;
    }

    public function slug(string $heading): string
    {
        return strtolower((string) $this->slugger->slug($heading));
    }

    /** @return list<string> */
    private function split(string $text): array
    {
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        if (mb_strlen($text) <= self::MAX_CHARS) {
            return [$text];
        }
        $parts = [];
        $buffer = '';
        foreach (explode("\n\n", $text) as $paragraph) {
            if ('' !== $buffer && mb_strlen($buffer) + mb_strlen($paragraph) > self::MAX_CHARS) {
                $parts[] = trim($buffer);
                $buffer = '';
            }
            $buffer .= $paragraph."\n\n";
        }
        if ('' !== trim($buffer)) {
            $parts[] = trim($buffer);
        }

        return $parts;
    }
}
