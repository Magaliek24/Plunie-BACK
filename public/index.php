<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Inclure config si elle existe
$config_path = __DIR__ . '/../../config.php';
if (file_exists($config_path)) {
    require_once $config_path;
} else {
    // Chemins par défaut si pas de config.php
    define('CORE_PATH', __DIR__ . '/../src/core');
    define('CONTROLLERS_PATH', __DIR__ . '/../src/controllers');
}

// Vérifier si le controller existe
$controller_path = CONTROLLERS_PATH . '/ContactController.php';
if (file_exists($controller_path)) {
    require_once $controller_path;
} else {
    die("ContactController NON trouvé au chemin: " . $controller_path);
}

use App\Controllers\ContactController;


// Router très simple
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Log pour debug
error_log("URI reçue : " . $uri);

// Retire /back/public si présent dans l'URL
$uri = str_replace('/back/public', '', $uri);
$uri = str_replace('/index.php', '', $uri);


if ($uri === '/contact/send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $controller = new ContactController();
        $controller->sendMessage();
    } catch (Exception $e) {
        die("ERREUR" . $e->getMessage());
    }
} else {
    http_response_code(404);
    echo "Page non trouvée." . htmlspecialchars($uri);
}
