<?php

declare(strict_types=1);

namespace App\Command;

use App\Answer\AnswerService;
use App\Answer\CitationVerifier;
use App\Eval\Question;
use App\Eval\QuestionSet;
use App\Eval\Text;
use App\Search\Hit;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Přepočítá souhrny E1–E4 z už uložených výsledků, bez nového volání modelů.
 * Použití po opravě vyhodnocení (kontrola citací, výběr otázek): odpovědi modelů zůstanou přesně ty změřené,
 * mění se jen to, jak je kód vyhodnotí. Do souboru zapíše, co se přepočítalo, a původní souhrn.
 * Pro kontrolu citací potřebuje zaindexované podklady v databázi (text úryvků podle section_key).
 */
#[AsCommand('app:prepocet', 'Přepočítá souhrny v vysledky/hledani.json a vysledky/odpovedi-*.json bez nového měření.')]
final class RecomputeCommand
{
    public function __construct(
        private readonly QuestionSet $questions,
        private readonly CitationVerifier $verifier,
        private readonly Connection $db,
        private readonly string $vysledkyDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Proč se přepočítává (zapíše se do souboru)')] string $duvod): int
    {
        $keep = [];
        foreach ($this->questions->all() as $q) {
            $keep[$q->id] = $q->answerable() || 'neodpovezitelna' === $q->kategorie;
        }

        $this->search($io, $keep, $duvod);
        foreach (glob($this->vysledkyDir.'/odpovedi-*.json') as $file) {
            $this->answers($io, $file, $keep, $duvod);
        }

        return 0;
    }

    /** @param array<string, bool> $keep */
    private function search(SymfonyStyle $io, array $keep, string $reason): void
    {
        $file = $this->vysledkyDir.'/hledani.json';
        $d = json_decode(file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $answerable = array_keys(array_filter($keep, fn (bool $k, string $id): bool => $k && $this->isAnswerable($id), \ARRAY_FILTER_USE_BOTH));

        // Otázky bez diakritiky mají ID „q001-bez“.
        $in = static fn (array $r): bool => \in_array(preg_replace('/-bez$/', '', $r['id']), $answerable, true);
        $rows = array_values(array_filter($d['otazky'], $in));
        $dropped = array_values(array_filter(array_column($d['otazky'], 'id'), static fn (string $id): bool => !str_ends_with($id, '-bez') && !\in_array($id, $answerable, true)));
        $variants = array_keys($d['varianty']);
        $latency = array_map(static fn (array $v): array => $v['latence_ms'], $d['varianty']);

        $before = array_map(static fn (array $v): array => $v['vse'], $d['varianty']);
        $agg = MeasureSearchCommand::summarize($rows, $variants, $latency);
        $groups = [
            'odpovedi_v_podkladech' => array_values(array_filter($d['skore']['skupiny']['odpovedi_v_podkladech'], static fn (array $r): bool => \in_array($r['id'], $answerable, true))),
            'neodpovezitelne' => $d['skore']['skupiny']['neodpovezitelne'],
        ];

        $d['otazek'] = \count($rows) / 2;
        $d['varianty'] = $agg['varianty'];
        $d['parova_srovnani_hit5_a_mrr'] = $agg['parova_srovnani_hit5_a_mrr'];
        $d['diakritika_rozdil_hit5'] = $agg['diakritika_rozdil_hit5'];
        $d['skore'] = MeasureSearchCommand::scoreSummary($groups);
        $d['otazky'] = $rows;
        $d['prepocet'] = ['kdy' => date('c'), 'duvod' => $reason, 'vyrazene_otazky' => $dropped, 'puvodni_souhrn_vse' => $before];
        $this->write($file, $d);

        $io->table(['Varianta', 'hit@1', 'hit@5', 'MRR'], array_map(static fn (string $v): array => [$v, $d['varianty'][$v]['vse']['hit1'], $d['varianty'][$v]['vse']['hit5'], $d['varianty'][$v]['vse']['mrr']], $variants));
    }

    /** @param array<string, bool> $keep */
    private function answers(SymfonyStyle $io, string $file, array $keep, string $reason): void
    {
        $d = json_decode(file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $rows = [];
        $dropped = [];
        $changed = 0;
        $verdicts = 0;
        foreach ($d['otazky'] as $r) {
            if (!($keep[$r['id']] ?? false)) {
                $dropped[] = $r['id'];
                continue;
            }
            // Verdikt znovu podle současné sady a rozpoznání „nevím“ (AnswerService::isRefusal).
            $q = $this->question($r['id']);
            $flag = $r['nevim'] !== $r['priznak_nevim_nesedi_s_textem'];   // původní příznak od modelu
            $r['nevim'] = AnswerService::isRefusal($r['odpoved']) || ($flag && [] === $r['citace']);
            $r['priznak_nevim_nesedi_s_textem'] = $flag !== $r['nevim'];
            if ($q->answerable()) {
                $r['chybejici_fakta'] = array_values(array_filter($q->fakta, static fn (string $f): bool => !Text::containsFact($r['odpoved'], $f)));
                $verdict = $r['nevim'] ? 'falesne_nevim' : ([] === $r['chybejici_fakta'] ? 'spravne' : 'spatne');
            } else {
                $verdict = $r['nevim'] ? 'spravne_nevim' : 'vymyslel';
            }
            $verdicts += (int) ($verdict !== $r['verdikt']);
            $r['verdikt'] = $verdict;

            $labels = [];
            foreach ($r['kontext'] as $c) {
                $labels[$c['usek']] = $this->hit($c['section']);
            }
            $check = $this->verifier->verify(['nevim' => $r['nevim'], 'odpoved' => $r['odpoved'], 'citace' => $r['citace']], $labels);
            $changed += (int) ($check !== $r['kontrola']);
            $r['kontrola'] = $check;
            $rows[] = $r;
        }

        $before = $d['souhrn'];
        $d['souhrn'] = MeasureAnswersCommand::summarize($rows);
        $d['otazky'] = $rows;
        $d['prepocet'] = ['kdy' => date('c'), 'duvod' => $reason, 'vyrazene_otazky' => $dropped,
            'zmenena_kontrola_citaci' => $changed, 'zmeneny_verdikt' => $verdicts, 'puvodni_souhrn' => $before];
        $this->write($file, $d);

        $io->section(basename($file));
        $io->writeln(json_encode($d['souhrn'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    }

    private function isAnswerable(string $id): bool
    {
        return $this->question($id)->answerable();
    }

    private function question(string $id): Question
    {
        foreach ($this->questions->all() as $q) {
            if ($q->id === $id) {
                return $q;
            }
        }

        throw new \RuntimeException("Otázka $id není v sadě.");
    }

    private function hit(string $sectionKey): Hit
    {
        $r = $this->db->fetchAssociative('SELECT id, document_id, section_key, title_path, content, pristup, zdroj FROM chunk WHERE section_key = ?', [$sectionKey])
            ?: throw new \RuntimeException("Úryvek $sectionKey není v databázi, zaindexujte podklady (app:index).");

        return new Hit($r['id'], $r['document_id'], $r['section_key'], $r['title_path'], $r['content'], $r['pristup'], $r['zdroj'], 0.0, 0);
    }

    /** @param array<string, mixed> $d */
    private function write(string $file, array $d): void
    {
        file_put_contents($file, json_encode($d, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION)."\n");
    }
}
