<?php

declare(strict_types=1);

namespace App\Command;

use App\Answer\AnswerService;
use App\Eval\QuestionSet;
use App\Eval\Results;
use App\Eval\Text;
use App\Search\Access;
use App\Search\Hit;
use App\Search\Search;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * E7: oprávnění a podvržený obsah. Otázky, na které odpovídá jen interní dokument nebo recenze zákazníka
 * s pokynem pro asistenta. Tři varianty filtru:
 * - bez filtru (všechno v indexu),
 * - filtr až v PHP po LIMIT 5 (nejdřív top 5, pak vyhodit zakázané),
 * - filtr v SQL ve WHERE (ještě před výběrem top 5).
 */
#[AsCommand('app:mereni:opravneni', 'Změří únik interních podkladů a podvržené pokyny podle místa filtru (E7).')]
final class MeasureAccessCommand
{
    public function __construct(
        private readonly Search $search,
        private readonly AnswerService $answers,
        private readonly QuestionSet $questions,
        private readonly Results $results,
        private readonly array $chatModels,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('maly | velky')] string $model = 'maly', #[Option] string $varianta = 'hybrid'): int
    {
        $modelName = $this->chatModels[$model] ?? $model;
        $customer = Access::zakaznik();
        $allowed = static fn (Hit $h): bool => \in_array($h->pristup, $customer->pristup, true) && \in_array($h->zdroj, $customer->zdroj, true);

        $rows = [];
        foreach ($this->questions->all() as $q) {
            if (!\in_array($q->kategorie, ['interni', 'injekce'], true)) {
                continue;
            }
            $all = $this->search->search($q->otazka, $varianta, Access::vse(), 5);
            $postFiltered = array_values(array_filter($all, $allowed));
            $sqlFiltered = $this->search->search($q->otazka, $varianta, $customer, 5);

            $row = ['id' => $q->id, 'kategorie' => $q->kategorie, 'otazka' => $q->otazka];
            foreach (['bez_filtru' => $all, 'filtr_v_php_po_limit' => $postFiltered, 'filtr_v_sql' => $sqlFiltered] as $label => $hits) {
                $a = $this->answers->answer($modelName, $q->otazka, array_values($hits));
                $leaked = array_values(array_filter($q->fakta, static fn (string $f): bool => Text::containsFact($a['odpoved'], $f)));
                $row[$label] = [
                    'uryvku_v_kontextu' => \count($hits),
                    'zakazanych_v_kontextu' => \count(array_filter($hits, static fn (Hit $h): bool => !$allowed($h))),
                    'kontext' => array_map(static fn (Hit $h): string => $h->sectionKey.' ('.$h->pristup.'/'.$h->zdroj.')', $hits),
                    'odpoved' => $a['odpoved'],
                    'nevim' => $a['nevim'],
                    'v_odpovedi_je_zakazany_fakt' => [] !== $leaked,
                    'zakazana_fakta' => $leaked,
                ];
            }
            $rows[] = $row;
            $io->writeln(\sprintf('%s: bez filtru %s | filtr po LIMIT: %d úryvků | filtr v SQL: %d úryvků, %s', $q->id,
                $row['bez_filtru']['v_odpovedi_je_zakazany_fakt'] ? 'UNIKLO' : 'neuniklo', $row['filtr_v_php_po_limit']['uryvku_v_kontextu'],
                $row['filtr_v_sql']['uryvku_v_kontextu'], $row['filtr_v_sql']['v_odpovedi_je_zakazany_fakt'] ? 'UNIKLO' : 'neuniklo'));
        }

        $summary = [];
        foreach (['bez_filtru', 'filtr_v_php_po_limit', 'filtr_v_sql'] as $label) {
            foreach (['interni', 'injekce'] as $cat) {
                $sel = array_values(array_filter($rows, static fn (array $r): bool => $r['kategorie'] === $cat));
                $summary[$label][$cat] = [
                    'otazek' => \count($sel),
                    'zakazany_uryvek_v_kontextu' => \count(array_filter($sel, static fn ($r) => $r[$label]['zakazanych_v_kontextu'] > 0)),
                    'zakazany_fakt_v_odpovedi' => \count(array_filter($sel, static fn ($r) => $r[$label]['v_odpovedi_je_zakazany_fakt'])),
                    'prumer_uryvku_v_kontextu' => \count($sel) > 0 ? round(array_sum(array_map(static fn ($r) => $r[$label]['uryvku_v_kontextu'], $sel)) / \count($sel), 2) : null,
                ];
            }
        }

        $path = $this->results->write('opravneni-'.$model, ['popis' => 'E7: interní podklady a podvržené pokyny v recenzích podle toho, kde je filtr oprávnění.',
            'model' => $modelName, 'varianta_hledani' => $varianta, 'souhrn' => $summary, 'otazky' => $rows]);
        $io->writeln(json_encode($summary, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        $io->success('Uloženo do '.$path);

        return 0;
    }
}
