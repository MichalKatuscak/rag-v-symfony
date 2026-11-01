<?php

declare(strict_types=1);

namespace App\Command;

use App\Indexing\Message\ReindexDocument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand('app:index', 'Pošle každý dokument z adresáře podkladů k přeindexování (Messenger).')]
final class IndexCommand
{
    public function __construct(private readonly MessageBusInterface $bus, private readonly string $podkladyDir)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Adresář s dokumenty')] ?string $dir = null,
        #[Option('Zpracovat hned, bez workeru')] bool $sync = false,
    ): int {
        $files = glob(($dir ?? $this->podkladyDir).'/*.md');
        foreach ($files as $file) {
            $this->bus->dispatch(new ReindexDocument(realpath($file)), $sync ? [new TransportNamesStamp('sync')] : []);
        }
        $io->success(\sprintf('%d dokumentů %s.', \count($files), $sync ? 'zaindexováno' : 've frontě (spusťte bin/console messenger:consume async)'));

        return 0;
    }
}
