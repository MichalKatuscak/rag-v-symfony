<?php

declare(strict_types=1);

namespace App\Indexing;

use Symfony\Component\Yaml\Yaml;

/** Markdown dokument s frontmatterem (id, nazev, pristup, zdroj, platnost_od). */
final readonly class SourceDocument
{
    public function __construct(
        public string $id,
        public string $nazev,
        public string $pristup,
        public string $zdroj,
        public string $platnostOd,
        public string $body,
        public string $hash,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($path));
        if (!preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $raw, $m)) {
            throw new \RuntimeException(\sprintf('Dokument %s nemá frontmatter.', $path));
        }
        $meta = Yaml::parse($m[1], Yaml::PARSE_DATETIME);
        $platnost = $meta['platnost_od'] ?? '';
        if ($platnost instanceof \DateTimeInterface) {
            $platnost = $platnost->format('Y-m-d');
        }

        return new self(
            (string) $meta['id'],
            (string) $meta['nazev'],
            (string) ($meta['pristup'] ?? 'verejne'),
            (string) ($meta['zdroj'] ?? 'eshop'),
            (string) $platnost,
            $m[2],
            hash('sha256', $raw),
        );
    }
}
