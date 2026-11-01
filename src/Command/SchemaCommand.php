<?php

declare(strict_types=1);

namespace App\Command;

use App\Indexing\Schema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:schema', 'Vytvoří tabulky (s --drop je nejdřív smaže).')]
final class SchemaCommand
{
    public function __construct(private readonly Schema $schema)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option] bool $drop = false): int
    {
        $this->schema->create($drop);
        $io->success('Schéma je připravené.');

        return 0;
    }
}
