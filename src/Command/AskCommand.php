<?php

declare(strict_types=1);

namespace App\Command;

use App\Answer\AnswerService;
use App\Search\Access;
use App\Search\Search;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:zeptej', 'Položí otázku: najde úryvky, nechá model odpovědět a ověří citace.')]
final class AskCommand
{
    public function __construct(private readonly Search $search, private readonly AnswerService $answers, private readonly array $chatModels)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument] string $otazka,
        #[Option('maly | velky')] string $model = 'maly',
        #[Option] string $varianta = 'hybrid',
        #[Option('Bez filtru oprávnění')] bool $bezFiltru = false,
    ): int {
        $hits = $this->search->search($otazka, $varianta, $bezFiltru ? Access::vse() : Access::zakaznik());
        $io->section('Nalezené úryvky');
        $io->table(['#', 'Sekce', 'Skóre', 'Přístup', 'Zdroj'], array_map(static fn ($h) => [$h->rank, $h->sectionKey, round($h->score, 4), $h->pristup, $h->zdroj], $hits));
        $a = $this->answers->answer($this->chatModels[$model] ?? $model, $otazka, $hits);
        $io->section('Odpověď');
        $io->writeln($a['odpoved']);
        $io->writeln('');
        $io->writeln(json_encode(['nevim' => $a['nevim'], 'citace' => $a['citace'], 'kontrola' => $a['kontrola'], 'metriky' => $a['metriky']], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
