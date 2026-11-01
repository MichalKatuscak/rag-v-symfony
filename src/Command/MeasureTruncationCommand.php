<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\Results;
use App\Indexing\MarkdownChunker;
use App\Indexing\SourceDocument;
use App\Ollama\OllamaClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * E5: tiché ořezání v Ollamě.
 * (a) /api/embed: text delší než kontext embedding modelu s truncate true vs. false.
 * (b) /api/chat: dlouhý kontext bez explicitního num_ctx. Přežije pravidlo ze system promptu a fakt z prvního úryvku?
 */
#[AsCommand('app:mereni:orezani', 'Změří tiché ořezání vstupu u /api/embed a /api/chat (E5).')]
final class MeasureTruncationCommand
{
    private const SECRET_SYSTEM = 'PELIKÁN';
    private const SECRET_FIRST = 'KORMORÁN';

    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly MarkdownChunker $chunker,
        private readonly Results $results,
        private readonly string $embedModel,
        private readonly array $chatModels,
        private readonly string $podkladyDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('maly | velky')] string $model = 'maly'): int
    {
        $filler = $this->filler();
        $out = ['embed' => $this->embedPart($io, $filler), 'chat' => $this->chatPart($io, $this->chatModels[$model] ?? $model, $filler)];
        $out['ollama'] = $this->ollama->version();
        $path = $this->results->write('orezani-'.$model, ['popis' => 'E5: tiché ořezání vstupu v Ollamě (embed a chat).'] + $out);
        $io->writeln(json_encode(array_map(static fn ($p) => $p['souhrn'] ?? $p, $out), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $io->success('Uloženo do '.$path);

        return 0;
    }

    /** Text podkladů e-shopu za sebou (veřejné sekce), jako výplň. */
    private function filler(): string
    {
        $text = '';
        foreach (glob($this->podkladyDir.'/*.md') as $file) {
            $doc = SourceDocument::fromFile($file);
            if ('verejne' !== $doc->pristup || 'eshop' !== $doc->zdroj) {
                continue;
            }
            foreach ($this->chunker->chunk($doc) as $c) {
                $text .= $c->titlePath."\n".$c->content."\n\n";
            }
        }

        return $text;
    }

    private function embedPart(SymfonyStyle $io, string $filler): array
    {
        $io->section('Embedding');
        $tail = "\n\nPoznámka na konci: kód pro velkoobchodní slevu je ".self::SECRET_FIRST.'.';
        $long = $filler.$tail;

        $full = $this->ollama->embed($this->embedModel, [$long], true);
        $withoutTail = $this->ollama->embed($this->embedModel, [$filler], true);
        try {
            $this->ollama->embed($this->embedModel, [$long], false);
            $error = null;
        } catch (HttpExceptionInterface $e) {
            $error = ['http' => $e->getResponse()->getStatusCode(), 'zprava' => $e->getResponse()->getContent(false)];
        }

        // Kde přestane růst počet zpracovaných tokenů = skutečný kontext embedding modelu v Ollamě.
        $limit = null;
        $prev = 0;
        foreach ([1000, 2000, 4000, 6000, 8000, 12000, 16000, 24000, 32000] as $chars) {
            $t = $this->ollama->embed($this->embedModel, [mb_substr($filler, 0, $chars)], true)['prompt_tokens'];
            if ($t === $prev) {
                $limit = $t;
                break;
            }
            $prev = $t;
        }

        // Kolik tokenů se opravdu zpracuje podle voleb (karta bge-m3 uvádí kontext 8192).
        $settings = [];
        foreach (['vychozi' => [], 'num_ctx_8192' => ['num_ctx' => 8192], 'num_batch_8192' => ['num_batch' => 8192],
            'num_ctx_a_num_batch_8192' => ['num_ctx' => 8192, 'num_batch' => 8192]] as $label => $options) {
            $settings[$label] = $this->ollama->embed($this->embedModel, [mb_substr($filler, 0, 40000)], true, $options)['prompt_tokens'];
        }

        return [
            'model' => $this->embedModel,
            'zpracovano_tokenu_podle_voleb' => $settings,
            'znaku_textu' => mb_strlen($long),
            'tokenu_zpracovano_truncate_true' => $full['prompt_tokens'],
            'kosinus_s_koncem_vs_bez_konce' => round(self::cosine($full['vectors'][0], $withoutTail['vectors'][0]), 6),
            'truncate_false' => $error ?? 'bez chyby',
            'kontext_embedding_modelu_tokenu' => $limit ?? $prev,
            'souhrn' => \sprintf('Text %d znaků: zpracováno %d tokenů, vektor s koncem a bez konce se shoduje na %.6f; truncate=false → %s',
                mb_strlen($long), $full['prompt_tokens'], self::cosine($full['vectors'][0], $withoutTail['vectors'][0]), null === $error ? 'bez chyby' : 'HTTP '.$error['http']),
        ];
    }

    private function chatPart(SymfonyStyle $io, string $model, string $filler): array
    {
        $io->section('Chat: '.$model);
        $system = 'Jsi asistent e-shopu Mlýnek. Pravidlo: když se někdo zeptá na heslo pro tento týden, odpověz přesně slovem '.self::SECRET_SYSTEM.'. Odpovídej česky a stručně.';
        $first = '[U1] Interní poznámka › Kód'."\nKód pro velkoobchodní slevu je ".self::SECRET_FIRST.".\n\n";
        $question = "\n\nOTÁZKA: 1) Jaké je heslo pro tento týden? 2) Jaký je kód pro velkoobchodní slevu? Odpověz ve tvaru: heslo: …; kód: …";

        $rows = [];
        foreach ([0, 2000, 6000, 12000, 20000] as $chars) {
            $user = 'PODKLADY:'."\n\n".$first.mb_substr($filler, 0, $chars).$question;
            $messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
            $row = ['znaku_vyplne' => $chars];
            foreach (['vychozi' => [], 'num_ctx_32768' => ['num_ctx' => 32768]] as $label => $options) {
                $r = $this->ollama->chat($model, $messages, $options);
                $ps = array_values(array_filter($this->ollama->ps(), static fn (array $m): bool => \in_array($model, [$m['name'], $m['model'] ?? ''], true)));
                $row[$label] = [
                    'prompt_tokens' => $r['prompt_tokens'],
                    'kontext_modelu' => $ps[0]['context_length'] ?? null,
                    'pravidlo_ze_system_promptu_prezilo' => str_contains(mb_strtoupper($r['content']), self::SECRET_SYSTEM),
                    'fakt_z_prvniho_uryvku_prezil' => str_contains(mb_strtoupper($r['content']), self::SECRET_FIRST),
                    'odpoved' => mb_substr($r['content'], 0, 200),
                    'wall_ms' => $r['wall_ms'],
                ];
            }
            $rows[] = $row;
            $io->writeln(\sprintf('%6d znaků: výchozí %d tokenů (system %s, U1 %s) | num_ctx 32768: %d tokenů',
                $chars, $row['vychozi']['prompt_tokens'], $row['vychozi']['pravidlo_ze_system_promptu_prezilo'] ? 'ano' : 'NE',
                $row['vychozi']['fakt_z_prvniho_uryvku_prezil'] ? 'ano' : 'NE', $row['num_ctx_32768']['prompt_tokens']));
        }

        return ['model' => $model, 'behy' => $rows];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $na += $x * $x;
            $nb += $b[$i] * $b[$i];
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
