<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\Results;
use App\Ollama\OllamaClient;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:meta', 'Zapíše verze, modely a prostředí do vysledky/meta.json.')]
final class MetaCommand
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly Connection $db,
        private readonly Results $results,
        private readonly string $embedModel,
        private readonly array $chatModels,
        private readonly int $numCtx,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $models = ['embed' => $this->ollama->info($this->embedModel)];
        foreach ($this->chatModels as $key => $model) {
            try {
                $models[$key] = $this->ollama->info($model);
            } catch (\Throwable $e) {
                $models[$key] = ['model' => $model, 'chyba' => $e->getMessage()];
            }
        }
        $head = @file_get_contents($this->projectDir.'/.git/HEAD');
        $data = [
            'ollama' => $this->ollama->version(),
            'gpu' => getenv('GPU_POPIS') ?: 'neuvedeno (nastavte GPU_POPIS, viz bin/mereni.ps1)',
            'php' => \PHP_VERSION,
            'symfony' => InstalledVersions::getPrettyVersion('symfony/framework-bundle'),
            'postgres' => $this->db->fetchOne('SHOW server_version'),
            'pgvector' => $this->db->fetchOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'"),
            'git' => getenv('GIT_HEAD') ?: (false !== $head ? trim($head) : null),
            'modely' => $models,
            'volby' => ['temperature' => 0, 'seed' => 42, 'think' => false, 'num_ctx' => $this->numCtx, 'truncate_pri_indexaci' => false],
            'podklady' => [
                'dokumentu' => (int) $this->db->fetchOne('SELECT count(*) FROM document'),
                'uryvku' => (int) $this->db->fetchOne('SELECT count(*) FROM chunk'),
                'tokenu_uryvku' => $this->db->fetchAssociative('SELECT min(tokens) AS min, percentile_disc(0.5) WITHIN GROUP (ORDER BY tokens) AS median, max(tokens) AS max FROM chunk'),
            ],
        ];
        $io->writeln(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $io->success('Uloženo do '.$this->results->write('meta', $data));

        return 0;
    }
}
