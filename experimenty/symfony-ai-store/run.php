<?php

/*
 * E6: Co udělá Symfony AI Store 1.0.0-BETA1 při přeindexování změněného dokumentu.
 *
 * Pipeline podle dokumentace: TextDocument → TextSplitTransformer (1000 znaků, překryv 200) → Vectorizer (bge-m3 v Ollamě)
 * → Postgres store (pgvector, kosinová metrika, 1024 dimenzí). Dokument „doprava“ se zaindexuje, pak se změní
 * (kávovary 5 → 7 pracovních dnů, PPL 129 → 139 Kč) a zaindexuje znovu se stejným ID dokumentu.
 *
 * Spuštění: docker compose run --rm -w /app/experimenty/symfony-ai-store app php run.php
 */

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Symfony\AI\Platform\Bridge\Ollama\Factory;
use Symfony\AI\Store\Bridge\Postgres\Metric;
use Symfony\AI\Store\Bridge\Postgres\Store;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\AI\Store\Retriever;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;

$root = dirname(__DIR__, 2);
$ollamaUrl = getenv('OLLAMA_URL') ?: 'http://localhost:11434';
$dsn = getenv('DATABASE_URL') ?: 'postgresql://rag:rag@127.0.0.1:55432/rag';
$u = parse_url($dsn);
$pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $u['host'], $u['port'] ?? 5432, ltrim($u['path'], '/')), $u['user'], $u['pass']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$platform = Factory::createPlatform($ollamaUrl, httpClient: HttpClient::create(['timeout' => 900]));
$vectorizer = new Vectorizer($platform, 'bge-m3');
$table = 'sfai_doprava';
$store = new Store($pdo, $table, metric: Metric::Cosine, dimensions: 1024);
$store->drop();
$store->setup();

$indexer = new DocumentIndexer(new DocumentProcessor($vectorizer, $store, transformers: [new TextSplitTransformer()]));
$documentId = Uuid::v5(Uuid::fromString('6ba7b811-9dad-11d1-80b4-00c04fd430c8'), 'mlynek.test/doprava')->toRfc4122();

$load = static function (string $file) use ($documentId): TextDocument {
    $raw = str_replace("\r\n", "\n", file_get_contents($file));
    $body = preg_replace('/\A---\n.*?\n---\n/s', '', $raw);

    return new TextDocument($documentId, $body, new Metadata(['source' => 'doprava.md']));
};

$snapshot = static function () use ($pdo, $table): array {
    $rows = $pdo->query(sprintf("SELECT id, metadata->>'_text' AS text FROM \"%s\" ORDER BY id", $table))->fetchAll(PDO::FETCH_ASSOC);

    return [
        'uryvku' => count($rows),
        's_5_pracovnimi_dny' => count(array_filter($rows, static fn ($r) => preg_match('/\b5 pracovních dn/u', $r['text']))),
        's_7_pracovnimi_dny' => count(array_filter($rows, static fn ($r) => preg_match('/\b7 pracovních dn/u', $r['text']))),
        's_129_kc' => count(array_filter($rows, static fn ($r) => preg_match('/\b129 Kč/u', $r['text']))),
        's_139_kc' => count(array_filter($rows, static fn ($r) => preg_match('/\b139 Kč/u', $r['text']))),
        'id' => array_column($rows, 'id'),
    ];
};

$original = $load($root.'/podklady/doprava.md');
$changed = $load($root.'/podklady-zmena/doprava.md');
$out = [
    'zmereno' => date('c'),
    'popis' => 'E6: přeindexování změněného dokumentu se Symfony AI Store 1.0.0-BETA1 (TextSplitTransformer 1000/200 + Postgres store, kosinus, 1024 dim).',
    'verze' => ['symfony/ai-store' => Composer\InstalledVersions::getPrettyVersion('symfony/ai-store'),
        'symfony/ai-postgres-store' => Composer\InstalledVersions::getPrettyVersion('symfony/ai-postgres-store'),
        'symfony/ai-ollama-platform' => Composer\InstalledVersions::getPrettyVersion('symfony/ai-ollama-platform')],
    'znaku_dokumentu' => ['puvodni' => mb_strlen($original->getContent()), 'zmeneny' => mb_strlen($changed->getContent())],
];

// 1. Původní verze.
$indexer->index($original);
$out['po_prvni_indexaci'] = $snapshot();

// 2. Změněná verze se stejným ID dokumentu, tak jak by to udělal handler „dokument se změnil“.
$indexer->index($changed);
$out['po_preindexovani'] = $snapshot();
$out['id_se_prekryvaji'] = count(array_intersect($out['po_prvni_indexaci']['id'], $out['po_preindexovani']['id']));

// 3. Co vrátí výchozí Retriever (HybridQuery, protože ho Postgres store umí) a čistě vektorový dotaz.
$question = 'Za jak dlouho odešlete pákový kávovar?';
$describe = static fn (iterable $docs): array => array_map(static fn ($d): array => [
    'id' => (string) $d->getId(),
    'skore' => null !== $d->getScore() ? round($d->getScore(), 4) : null,
    'obsahuje' => array_values(array_filter([
        preg_match('/\b5 pracovních dn/u', $d->getMetadata()['_text'] ?? '') ? 'kávovary 5 dnů (stará)' : null,
        preg_match('/\b7 pracovních dn/u', $d->getMetadata()['_text'] ?? '') ? 'kávovary 7 dnů (nová)' : null,
        preg_match('/\b129 Kč/u', $d->getMetadata()['_text'] ?? '') ? 'PPL 129 Kč (stará)' : null,
        preg_match('/\b139 Kč/u', $d->getMetadata()['_text'] ?? '') ? 'PPL 139 Kč (nová)' : null,
    ])),
    'text' => (string) ($d->getMetadata()['_text'] ?? ''),
], is_array($docs) ? $docs : iterator_to_array($docs, false));

$retriever = new Retriever($store, $vectorizer);
$out['dotaz'] = $question;
$out['retriever_vychozi_hybrid'] = $describe($retriever->retrieve($question, ['limit' => 5]));
// Pozor: VectorQuery vrací ve „score“ kosinovou VZDÁLENOST (menší = podobnější), HybridQuery vážené skóre (větší = lepší).
$out['vektorovy_dotaz_skore_je_vzdalenost'] = $describe($store->query(new Symfony\AI\Store\Query\VectorQuery($vectorizer->vectorize($question)), ['limit' => 5]));

// Co z toho chatbot odpoví: stejný prompt, model a kontrola citací jako v hlavní aplikaci (App\Answer\AnswerService).
$hits = [];
foreach ($out['retriever_vychozi_hybrid'] as $i => $d) {
    $hits[] = new App\Search\Hit($d['id'], 'doprava', 'doprava', 'Doprava a dodací lhůty', $d['text'], 'verejne', 'eshop', (float) $d['skore'], $i + 1);
}
$chatModel = getenv('CHAT_MODEL') ?: 'gemma4:e2b';
$answers = new App\Answer\AnswerService(new App\Ollama\OllamaClient(HttpClient::create(), $ollamaUrl), new App\Answer\CitationVerifier(), 8192);
$a = $answers->answer($chatModel, $question, $hits);
$out['odpoved_chatbota'] = ['model' => $chatModel, 'odpoved' => $a['odpoved'], 'citace' => $a['citace'], 'kontrola_citaci' => $a['kontrola']];

// 4. Oprava bez změny knihovny: před přeindexováním smazat úryvky dokumentu podle _parent_id.
$store->drop();
$store->setup();
$indexer->index($original);
$delete = $pdo->prepare(sprintf("DELETE FROM \"%s\" WHERE metadata->>'_parent_id' = ?", $table));
$delete->execute([$documentId]);
$indexer->index($changed);
$out['oprava_smazat_podle_parent_id'] = array_diff_key($snapshot(), ['id' => 1]);

unset($out['po_prvni_indexaci']['id'], $out['po_preindexovani']['id']);
@mkdir($root.'/vysledky');
file_put_contents($root.'/vysledky/symfony-ai-store.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
