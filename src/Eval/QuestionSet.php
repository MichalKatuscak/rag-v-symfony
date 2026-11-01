<?php

declare(strict_types=1);

namespace App\Eval;

use Symfony\Component\Yaml\Yaml;

final class QuestionSet
{
    public function __construct(private readonly string $otazkyFile)
    {
    }

    /** @return list<Question> */
    public function all(): array
    {
        $out = [];
        foreach (Yaml::parseFile($this->otazkyFile)['otazky'] as $q) {
            $out[] = new Question((string) $q['id'], (string) $q['otazka'], (string) $q['kategorie'],
                array_values($q['spravne'] ?? []), array_map('strval', array_values($q['fakta'] ?? [])));
        }

        return $out;
    }

    /** @return list<Question> */
    public function answerable(): array
    {
        return array_values(array_filter($this->all(), static fn (Question $q): bool => $q->answerable()));
    }
}
