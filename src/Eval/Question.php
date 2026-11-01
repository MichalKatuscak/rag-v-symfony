<?php

declare(strict_types=1);

namespace App\Eval;

final readonly class Question
{
    /**
     * @param list<string> $spravne sekce, ve kterých je odpověď (např. doprava#kavovary)
     * @param list<string> $fakta   krátké řetězce, které musí být ve správné odpovědi (hlavně čísla)
     */
    public function __construct(
        public string $id,
        public string $otazka,
        public string $kategorie,
        public array $spravne,
        public array $fakta,
    ) {
    }

    public function answerable(): bool
    {
        return [] !== $this->spravne && !\in_array($this->kategorie, ['neodpovezitelna', 'interni'], true);
    }

    /** Stejná otázka bez háčků a čárek („kdy prijde kavovar“). */
    public function withoutDiacritics(): self
    {
        return new self($this->id.'-bez', Text::stripDiacritics($this->otazka), $this->kategorie, $this->spravne, $this->fakta);
    }
}
