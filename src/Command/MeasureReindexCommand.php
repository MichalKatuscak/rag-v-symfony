<?php

declare(strict_types=1);

namespace App\Command;

use App\Answer\AnswerService;
use App\Eval\Results;
use App\Indexing\Indexer;
use App\Indexing\Message\ReindexDocument;
use App\Indexing\SourceDocument;
use App\Search\Access;
use App\Search\Search;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * E6 (naše strana): stejný scénář jako experimenty/symfony-ai-store, ale s deterministickými ID úryvků.
 * Změna dokumentu doprava (kávovary 5 → 7 pracovních dnů, PPL 129 → 139 Kč), přeindexování přes Messenger,
 * dvojí doručení stejné zprávy (idempotence) a odpověď chatbota před a po změně. Na konci se vrátí původní verze.
 */
#[AsCommand('app:mereni:preindexovani', 'Změří přeindexování změněného dokumentu s deterministickými ID (E6).')]
final class MeasureReindexCommand
{
    private const QUESTION = 'Za jak dlouho odešlete pákový kávovar?';

    public function __construct(
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
        private readonly Indexer $indexer,
        private readonly Search $search,
        private readonly AnswerService $answers,
        private readonly Results $results,
        private readonly array $chatModels,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('maly | velky')] string $model = 'maly'): int
    {
        $original = $this->projectDir.'/podklady/doprava.md';
        $changed = $this->projectDir.'/podklady-zmena/doprava.md';
        $modelName = $this->chatModels[$model] ?? $model;

        $this->indexer->index(SourceDocument::fromFile($original));
        $out = ['puvodni' => $this->snapshot() + ['odpoved' => $this->ask($modelName)]];

        // Změna dokumentu → zpráva do Messengeru. Doručíme ji dvakrát (retry / duplicita), výsledek musí být stejný.
        $this->bus->dispatch(new ReindexDocument($changed), [new TransportNamesStamp('sync')]);
        $out['po_zmene'] = $this->snapshot();
        $this->bus->dispatch(new ReindexDocument($changed), [new TransportNamesStamp('sync')]);
        $out['po_druhem_doruceni_stejne_zpravy'] = $this->snapshot();
        $out['po_zmene']['odpoved'] = $this->ask($modelName);

        // Úklid: zpět na původní verzi, ať ostatní měření běží nad stejnými podklady.
        $this->indexer->index(SourceDocument::fromFile($original));
        $out['po_navratu'] = $this->snapshot();

        $path = $this->results->write('preindexovani', ['popis' => 'E6: přeindexování změněného dokumentu doprava s deterministickými ID úryvků (UUID v5) a mazáním chybějících.', 'otazka' => self::QUESTION, 'varianta_hledani' => 'vektor', 'model' => $modelName] + $out);
        $io->writeln(json_encode($out, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $io->success('Uloženo do '.$path);

        return 0;
    }

    private function snapshot(): array
    {
        $rows = $this->db->fetchAllAssociative("SELECT content FROM chunk WHERE document_id = 'doprava'");
        $count = static fn (string $re): int => \count(array_filter($rows, static fn (array $r): bool => (bool) preg_match($re, $r['content'])));

        return [
            'uryvku' => \count($rows),
            's_5_pracovnimi_dny' => $count('/\b5 pracovních dn/u'),
            's_7_pracovnimi_dny' => $count('/\b7 pracovních dn/u'),
            's_129_kc' => $count('/\b129 Kč/u'),
            's_139_kc' => $count('/\b139 Kč/u'),
        ];
    }

    private function ask(string $model): array
    {
        // Nejlepší varianta bez rerankeru podle E1 (reranker v téhle fázi neběží). S hybridem úryvek doprava#kavovary
        // vypadl z top 5 (RTX 4060, 1. 11.) a model správně řekl „nevím“ před změnou i po ní.
        $hits = $this->search->search(self::QUESTION, 'vektor', Access::zakaznik());
        $a = $this->answers->answer($model, self::QUESTION, $hits);

        return ['text' => $a['odpoved'], 'citace' => $a['citace'], 'kontrola' => $a['kontrola'], 'top' => array_map(static fn ($h) => $h->sectionKey, $hits)];
    }
}
