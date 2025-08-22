<?php

declare(strict_types=1);

// 1) Autoload depuis la racine (public/ est un sous-dossier)
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 2) Dotenv (.env à la racine)
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// 3) Constantes / config app (optionnel)
define('APP_NAME', $_ENV['APP_NAME'] ?? 'MonApplication');
define('APP_ENV',  $_ENV['APP_ENV']  ?? 'production');
define('APP_URL',  $_ENV['APP_URL']  ?? 'http://localhost:8080'); // ou BASE_URL si tu préfères

// 4) Erreurs & timezone pilotés par .env
$debug = (int)($_ENV['APP_DEBUG'] ?? 0) === 1;
ini_set('display_errors', $debug ? '1' : '0');
error_reporting($debug ? E_ALL : (E_ALL & ~E_DEPRECATED & ~E_STRICT));

date_default_timezone_set(
    $_ENV['APP_TIMEZONE'] // si tu utilises APP_TIMEZONE
        ?? $_ENV['TIMEZONE']  // ou TIMEZONE
        ?? 'Europe/Paris'
);

// 5) Session (panier, auth…)
session_start();

// 6) Connexion PDO (port + options)
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? 'pluniedb',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'plunie'
    );
    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USER'] ?? 'root',
        // harmonise: DB_PASS OU DB_PASSWORD (choisis et reste cohérente)
        $_ENV['DB_PASS'] ?? $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('Erreur de connexion à la base de données : ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}

// 7) Mini-router (exemple avec ta route contact)
use App\Controllers\ContactController;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
// Si ton projet est servi sous /back/public, garde ces normalisations :
$uri = str_replace('/back/public', '', $uri);
$uri = str_replace('/index.php', '', $uri);

try {
    if ($uri === '/contact/send' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // ajuste le constructeur selon ton contrôleur (avec ou sans $pdo)
        $controller = class_exists(ContactController::class)
            ? new ContactController(/* $pdo */)
            : null;

        if (!$controller) {
            http_response_code(500);
            exit('ContactController introuvable.');
        }

        $out = $controller->sendMessage();
        if ($out !== null) echo $out;
        exit;
    }

    // 404 par défaut
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Page non trouvée : " . htmlspecialchars($uri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
} catch (Throwable $e) {
    http_response_code(500);
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur: {$e->getMessage()}\n";
        echo "Fichier: {$e->getFile()}:{$e->getLine()}\n\n";
        echo $e->getTraceAsString();
    } else {
        echo "Une erreur est survenue. Réessayez plus tard.";
    }
}
