<?php

declare(strict_types=1);

namespace App\Eval;

final class Results
{
    public function __construct(private readonly string $vysledkyDir)
    {
    }

    public function write(string $name, array $data): string
    {
        if (!is_dir($this->vysledkyDir)) {
            mkdir($this->vysledkyDir, 0777, true);
        }
        $path = $this->vysledkyDir.'/'.$name.'.json';
        file_put_contents($path, json_encode(['zmereno' => date('c')] + $data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION)."\n");

        return $path;
    }
}
