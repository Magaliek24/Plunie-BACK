<?php
// bootstrap.php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

date_default_timezone_set('Europe/Paris');

$env = $_ENV['APP_ENV'] ?? 'prod';
$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

// dossier logs
$logDir = __DIR__ . '/var/log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

ini_set('error_log', $logDir . '/php_error.log');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', $debug ? '1' : '0');

// petite aide JSON (temporaire)
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
