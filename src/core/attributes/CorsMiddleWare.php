<?php

declare(strict_types=1);

namespace App\core\attributes;

class CorsMiddleWare
{
    public static function handle(): void
    {
        $origins = array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOW_ORIGINS'] ?? '')));
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? null;
        $appUrl  = $_ENV['APP_URL'] ?? null;

        $allowedOrigin = $origin && (in_array($origin, $origins, true) || (!$origins && $appUrl && $origin === $appUrl));


        // 1. Vérifie si l'origine existe
        // 2. Soit elle est dans la liste autorisée
        // 3. Soit (si pas de liste) elle correspond à APP_URL 
        if ($allowedOrigin) {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
        // Si aucune condition n'est remplie, pas de header CORS = requête bloquée

        // Methods
        header('Access-Control-Allow-Methods: ' . ($_ENV['CORS_ALLOW_METHODS'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD'));

        // Headers: reflète la demande si présente, sinon fallback “large”
        $reqHeaders     = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
        $fallbackHeaders = $_ENV['CORS_ALLOW_HEADERS'] ?? 'Content-Type, Authorization, X-CSRF-Token, X-Requested-With, Accept';
        header('Access-Control-Allow-Headers: ' . ($reqHeaders ?: $fallbackHeaders));
        if ($reqHeaders) {
            header('Vary: Access-Control-Request-Headers');
        }

        // Expose pour que le front puisse lire le token renvoyé par /api/csrf
        header('Access-Control-Expose-Headers: ' . ($_ENV['CORS_EXPOSE_HEADERS'] ?? 'X-CSRF-Token'));

        // Cache du préflight
        header('Access-Control-Max-Age: ' . (int)($_ENV['CORS_MAX_AGE'] ?? 600));

        // Réponse préflight
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code($allowedOrigin ? 204 : 403);
            exit;
        }
    }
}
