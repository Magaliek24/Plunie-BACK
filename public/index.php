<?php

declare(strict_types=1);

// bootstrap (autoload, dotenv, logs, helpers)
require dirname(__DIR__) . '/bootstrap.php';

\App\core\attributes\CorsMiddleWare::handle();

// Session (panier/auth)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Génère un token CSRF si absent (dev & prod)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri = str_replace(['/back/public', '/index.php'], '', $uri);
if ($uri === '') $uri = '/';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Routes minimalistes
try {
    $routeKey = $method . ' ' . $uri;

    switch ($routeKey) {
        // Health-check + CSRF
        case 'GET /':
        case 'GET /health':
            json_response([
                'ok'   => true,
                'env'  => $_ENV['APP_ENV'] ?? 'prod',
                'time' => date('c')
            ]);
            break;
        case 'GET /debug/csrf':
            json_response(['csrf' => $_SESSION['csrf_token'] ?? null]);
            break;

        case 'GET /api/health':
            json_response([
                'ok'   => true,
                'env'  => $_ENV['APP_ENV'] ?? 'prod',
                'time' => date('c')
            ]);
            break;

        // Formulaire de contact (POST /contact/send)
        case 'POST /contact/send':
            // Supporte JSON (fetch) et form classique
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $raw  = file_get_contents('php://input') ?: '';
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $_POST = $data + $_POST; // dispo pour le contrôleur
                }
            }

            $controller = new \App\controllers\ContactController();

            if (!method_exists($controller, 'sendMessage')) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Méthode sendMessage() absente dans ContactController.";
                break;
            }

            $result = $controller->sendMessage();

            // Si le contrôleur a déjà envoyé des headers ou du contenu, on sort.
            if (headers_sent()) {
                exit;
            }

            // Si le contrôleur renvoie quelque chose, on l’affiche proprement
            if ($result !== null) {
                if (is_array($result)) {
                    json_response($result, 200);
                } elseif (is_string($result)) {
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $result;
                } else {
                    json_response(['ok' => true], 200);
                }
            } else {
                json_response(['ok' => true], 200);
            }
            break;

        case 'GET /api/categories':
            $cat = new \App\controllers\CategoryController();
            $data = $cat->list();
            json_response(['ok' => true, 'data' => $data]);
            break;

        case 'GET /api/products':
            $prod = new \App\controllers\ProductController();
            // on passe $_GET pour plus tard (filtres/pagination)
            $data = $prod->list($_GET);
            json_response(['ok' => true, 'data' => $data]);
            break;

        case 'GET /api/debug/db':
            try {
                $pdo = \App\core\Database::pdo();
                $ver = $pdo->query("SELECT VERSION() AS v")->fetch()['v'] ?? null;

                $counts = $pdo->query("
                    SELECT 
                    (SELECT COUNT(*) FROM categories)           AS categories,
                    (SELECT COUNT(*) FROM produits)             AS produits,
                    (SELECT COUNT(*) FROM variations_produits)  AS variations,
                    (SELECT COUNT(*) FROM images_produits)      AS images,
                    (SELECT COUNT(*) FROM commandes)            AS commandes,
                    (SELECT COUNT(*) FROM avis_produits)        AS avis
                ")->fetch();

                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'version' => $ver, 'counts' => $counts], JSON_UNESCAPED_UNICODE);
            } catch (\Throwable $e) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;


            // 404
        default:
            if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $uri, $m)) {
                $id = (int)$m[1];
                $prod = new \App\controllers\ProductController();
                $data = $prod->show($id);
                json_response(['ok' => true, 'data' => $data]);
                break;
            }
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Not Found', 'path' => $uri], JSON_UNESCAPED_UNICODE);
            exit;
    }
} catch (\Throwable $e) {
    $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
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
