<?php

declare(strict_types=1);

namespace App\Search;

/** Kdo se ptá: filtr se uplatní v SQL (WHERE) ještě před výběrem nejlepších úryvků. */
final readonly class Access
{
    /**
     * @param list<string> $pristup povolené úrovně (verejne, interni)
     * @param list<string> $zdroj   povolené zdroje (eshop, dodavatel, zakaznik)
     */
    public function __construct(public array $pristup, public array $zdroj)
    {
    }

    /** Zákazník: jen veřejné podklady e-shopu a dodavatelů, ne texty od jiných zákazníků. */
    public static function zakaznik(): self
    {
        return new self(['verejne'], ['eshop', 'dodavatel']);
    }

    /** Bez filtru: všechno, co je v indexu (tak to dopadne, když filtr zapomenete). */
    public static function vse(): self
    {
        return new self(['verejne', 'interni'], ['eshop', 'dodavatel', 'zakaznik']);
    }
}
