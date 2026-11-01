<?php

declare(strict_types=1);

namespace App\Indexing;

final class Vector
{
    /** @param list<float> $v */
    public static function literal(array $v): string
    {
        return '['.implode(',', array_map(static fn (float $x): string => rtrim(\sprintf('%.8F', $x), '0'), $v)).']';
    }
}
