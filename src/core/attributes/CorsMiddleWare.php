<?php

declare(strict_types=1);

namespace App\core\attributes;

class CorsMiddleWare
{
    public static function handle(): void
    {
        $origins = array_map('trim', explode(',', $_ENV['CORS_ALLOW_ORIGINS'] ?? ''));
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '*';

        if (in_array($origin, $origins, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: ' . ($_ENV['CORS_ALLOW_HEADERS'] ?? 'Content-Type,Authorization'));
        header('Access-Control-Allow-Methods: ' . ($_ENV['CORS_ALLOW_METHODS'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS'));

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
