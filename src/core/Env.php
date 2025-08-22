
<?php

declare(strict_types=1);

namespace App\core;

final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        // priorité à $_ENV puis $_SERVER
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}
