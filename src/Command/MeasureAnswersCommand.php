<?php

declare(strict_types=1);

namespace App\Command;

use App\Answer\AnswerService;
use App\Eval\QuestionSet;
use App\Eval\Results;
use App\Eval\Text;
use App\Search\Access;
use App\Search\Search;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * E4: proč chatbot „lže“. Pro každou otázku: najít úryvky → odpověď modelu → kontrola citací kódem
 * → porovnání s očekávanými fakty. Chyby se rozdělí na chyby hledání (správný úryvek v kontextu nebyl)
 * a chyby modelu (byl, a stejně odpověděl špatně).
 */
#[AsCommand('app:mereni:odpovedi', 'Změří odpovědi modelu, „nevím“ a citace (E4).')]
final class MeasureAnswersCommand
{
    public function __construct(
        private readonly Search $search,
        private readonly AnswerService $answers,
        private readonly QuestionSet $questions,
        private readonly Results $results,
        private readonly array $chatModels,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('maly | velky')] string $model = 'maly',
        #[Option] string $varianta = 'hybrid',
        #[Option('Kolik úryvků dostane model')] int $uryvku = 5,
        #[Option('Jen prvních N otázek (ladění)')] ?int $limit = null,
    ): int {
        $modelName = $this->chatModels[$model] ?? $model;
        $questions = array_values(array_filter($this->questions->all(), static fn ($q): bool => $q->answerable() || 'neodpovezitelna' === $q->kategorie));
        if (null !== $limit) {
            $questions = \array_slice($questions, 0, $limit);
        }

        $rows = [];
        $io->progressStart(\count($questions));
        foreach ($questions as $q) {
            $hits = $this->search->search($q->otazka, $varianta, Access::zakaznik(), $uryvku);
            $a = $this->answers->answer($modelName, $q->otazka, $hits);
            $goldInContext = [] !== array_intersect($q->spravne, array_map(static fn ($h) => $h->sectionKey, $hits));

            if ($q->answerable()) {
                $missing = array_values(array_filter($q->fakta, static fn (string $f): bool => !Text::containsFact($a['odpoved'], $f)));
                $verdict = $a['nevim'] ? 'falesne_nevim' : ([] === $missing ? 'spravne' : 'spatne');
            } else {
                $missing = [];
                $verdict = $a['nevim'] ? 'spravne_nevim' : 'vymyslel';
            }
            $rows[] = ['id' => $q->id, 'kategorie' => $q->kategorie, 'otazka' => $q->otazka, 'verdikt' => $verdict,
                'spravny_uryvek_v_kontextu' => $goldInContext, 'chybejici_fakta' => $missing] + $a;
            $io->progressAdvance();
        }
        $io->progressFinish();

        $answerable = array_values(array_filter($rows, static fn (array $r): bool => 'neodpovezitelna' !== $r['kategorie']));
        $unanswerable = array_values(array_filter($rows, static fn (array $r): bool => 'neodpovezitelna' === $r['kategorie']));
        $count = static fn (array $rs, callable $f): int => \count(array_filter($rs, $f));

        $wrong = static fn (array $r): bool => \in_array($r['verdikt'], ['spatne', 'vymyslel'], true);
        $summary = [
            'odpovedi_v_podkladech' => [
                'n' => \count($answerable),
                'spravne' => $count($answerable, static fn ($r) => 'spravne' === $r['verdikt']),
                'spatne' => $count($answerable, static fn ($r) => 'spatne' === $r['verdikt']),
                'falesne_nevim' => $count($answerable, static fn ($r) => 'falesne_nevim' === $r['verdikt']),
                // Rozpad: chyba hledání vs. chyba modelu.
                'spatne_a_uryvek_chybel' => $count($answerable, static fn ($r) => 'spravne' !== $r['verdikt'] && !$r['spravny_uryvek_v_kontextu']),
                'spatne_i_kdyz_uryvek_byl' => $count($answerable, static fn ($r) => 'spravne' !== $r['verdikt'] && $r['spravny_uryvek_v_kontextu']),
            ],
            'neodpovezitelne' => [
                'n' => \count($unanswerable),
                'rekl_nevim' => $count($unanswerable, static fn ($r) => 'spravne_nevim' === $r['verdikt']),
                'vymyslel' => $count($unanswerable, static fn ($r) => 'vymyslel' === $r['verdikt']),
            ],
            'kontrola_citaci' => [
                // Kolik špatných odpovědí kontrola chytila a kolik dobrých zbytečně označila.
                'spatnych_odpovedi' => $count($rows, $wrong),
                'spatnych_chycenych_kontrolou' => $count($rows, static fn ($r) => $wrong($r) && !$r['kontrola']['v_poradku']),
                'spravnych_odpovedi' => $count($rows, static fn ($r) => 'spravne' === $r['verdikt']),
                'spravnych_oznacenych_kontrolou' => $count($rows, static fn ($r) => 'spravne' === $r['verdikt'] && !$r['kontrola']['v_poradku']),
                'neplatny_usek' => $count($rows, static fn ($r) => [] !== $r['kontrola']['neplatne_useky']),
                'citat_neni_v_uryvku' => $count($rows, static fn ($r) => [] !== $r['kontrola']['citaty_nenalezene']),
                'cislo_mimo_citace' => $count($rows, static fn ($r) => [] !== $r['kontrola']['cisla_mimo_citace']),
                'bez_citace' => $count($rows, static fn ($r) => $r['kontrola']['bez_citace']),
                'neplatny_json' => $count($rows, static fn ($r) => !$r['json_platny']),
                'priznak_nevim_nesedi_s_textem' => $count($rows, static fn ($r) => $r['priznak_nevim_nesedi_s_textem']),
            ],
            'cas_ms' => [
                'p50' => MeasureSearchCommand::percentiles(array_map(static fn ($r) => $r['metriky']['wall_ms'], $rows))['p50'],
                'p95' => MeasureSearchCommand::percentiles(array_map(static fn ($r) => $r['metriky']['wall_ms'], $rows))['p95'],
                'prompt_tokens_p50' => MeasureSearchCommand::percentiles(array_map(static fn ($r) => (float) $r['metriky']['prompt_tokens'], $rows))['p50'],
            ],
        ];

        $path = $this->results->write('odpovedi-'.$model, [
            'popis' => 'E4: odpovědi modelu nad nalezenými úryvky, kontrola citací kódem, „nevím“ u otázek mimo podklady.',
            'model' => $modelName, 'varianta_hledani' => $varianta, 'uryvku' => $uryvku,
            'system_prompt' => AnswerService::SYSTEM,
            'souhrn' => $summary,
            'otazky' => $rows,
        ]);
        $io->writeln(json_encode($summary, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $io->success('Uloženo do '.$path);

        return 0;
    }
}
