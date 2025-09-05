<?php

declare(strict_types=1);

// bootstrap (autoload, dotenv, logs, helpers)
require dirname(__DIR__) . '/bootstrap.php';

use App\core\attributes\CorsMiddleWare;

CorsMiddleWare::handle();


// Génère un token CSRF si absent (dev & prod)
generate_csrf_token();

// Petits helpers locaux
$DEBUG = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}
function has_location_header(): bool
{
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) return true;
    }
    return false;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (is_array($data)) {
            // on injecte dans $_POST pour que les contrôleurs existants continuent de marcher
            $_POST = $data + $_POST;
        }
    }
}
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
        case 'GET /api/health':
            json_response([
                'ok'   => true,
                'env'  => $_ENV['APP_ENV'] ?? 'prod',
                'time' => date('c')
            ]);
            break;

        // Formulaire de contact (form & ajax)
        case 'POST /contact/send':
            $controller = new \App\controllers\ContactController();

            if (!method_exists($controller, 'sendMessage')) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Méthode sendMessage() absente dans ContactController.";
                break;
            }

            $result = $controller->sendMessage();

            // Si le contrôleur a posé une redirection, on sort immédiatement
            if (has_location_header() || headers_sent()) {
                exit;
            }

            // Sinon, normalise la réponse
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

        case 'GET /api/csrf':
            // renvoie le token courant (et en crée un s’il n’existe pas encore)
            $token = generate_csrf_token();

            header('X-CSRF-Token: ' . $token);
            header('Cache-Control: no-store');

            json_response([
                'ok' => true,
                'csrf' => $token,
                // aides debug "légères" (pas besoin d'APP_DEBUG ici)
                'session_id' => session_id(),
                'csrf_preview' => substr($token, 0, 12) . '…',
                'has_cookie' => isset($_COOKIE['PHPSESSID']),
            ]);
            break;



        // ---- Catalogue simple ----
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

        case 'GET /api/cart':
            $cart = new \App\controllers\CartController();
            $res  = $cart->get();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'POST /api/cart/items':
            $cart = new \App\controllers\CartController();
            $res  = $cart->addItem();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'DELETE /api/cart':
            $cart = new \App\controllers\CartController();
            $res  = $cart->clear();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'POST /api/auth/register':
            $auth = new \App\controllers\AuthController();
            $res  = $auth->register();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'POST /api/auth/login':
            $auth = new \App\controllers\AuthController();
            $res  = $auth->login();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'GET /api/auth/me':
            $auth = new \App\controllers\AuthController();
            $res  = $auth->me();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'POST /api/auth/logout':
            $auth = new \App\controllers\AuthController();
            $res  = $auth->logout();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'GET /api/addresses':
            $ctl = new \App\controllers\AddressController();
            $res = $ctl->list();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'POST /api/addresses':
            $ctl = new \App\controllers\AddressController();
            $res = $ctl->create();
            json_response($res['body'] ?? [], $res['status'] ?? 201);
            break;

        // ---- Commandes ----
        case 'POST /api/orders/checkout':
            $ctl = new \App\controllers\OrderController();
            $res = $ctl->checkout();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;

        case 'GET /api/orders':
            $ctl = new \App\controllers\OrderController();
            $res = $ctl->listMine();
            json_response($res['body'] ?? [], $res['status'] ?? 200);
            break;


        // Debug
        case 'GET /debug/csrf':
            if (!$DEBUG) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Not Found']);
                break;
            }
            json_response(['csrf' => $_SESSION['csrf_token'] ?? null]);
            break;

        case 'POST /debug/csrf/echo':
            if (!$DEBUG) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Not Found']);
                break;
            }
            $raw  = file_get_contents('php://input') ?: '';
            $data = json_decode($raw, true) ?: [];
            $sess = $_SESSION['csrf_token'] ?? null;
            $hdr  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $bod  = (string)($data['csrf_token'] ?? '');
            json_response([
                'ok' => true,
                'session_id' => session_id(),
                'session_csrf_preview'    => $sess ? substr($sess, 0, 12) . '…' : null,
                'provided_header_preview' => $hdr ? substr($hdr, 0, 12) . '…' : null,
                'provided_body_preview'   => $bod ? substr($bod, 0, 12) . '…' : null,
                'eq_header' => ($sess && hash_equals($sess, $hdr)),
                'eq_body'   => ($sess && hash_equals($sess, $bod)),
            ]);
            break;

        case 'GET /debug/whoami':
            if (!$DEBUG) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Not Found']);
                break;
            }
            json_response([
                'session_id'   => session_id(),
                'user_id'      => $_SESSION['user_id'] ?? null,
                'user_role'         => $_SESSION['user_role'] ?? null,
                'csrf_preview' => isset($_SESSION['csrf_token']) ? substr($_SESSION['csrf_token'], 0, 12) . '…' : null,
                'has_cookie'   => isset($_COOKIE['PHPSESSID']),
            ]);
            break;

        case 'GET /api/debug/db':
            if (!$DEBUG) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Not Found']);
                break;
            }
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
                json_response(['ok' => true, 'version' => $ver, 'counts' => $counts]);
            } catch (\Throwable $e) {
                json_response(['ok' => false, 'error' => $e->getMessage()], 500);
            }
            break;


        // 404
        default:
            // GET /api/products/{id}
            if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $uri, $m)) {
                $id = (int)$m[1];
                $prod = new \App\controllers\ProductController();
                $data = $prod->show($id);
                json_response(['ok' => true, 'data' => $data]);
                break;
            }

            // PATCH /api/cart/items/{variationId}
            if ($method === 'PATCH' && preg_match('#^/api/cart/items/(\d+)$#', $uri, $m)) {
                $cart = new \App\controllers\CartController();
                $res  = $cart->updateItem((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 200);
                break;
            }

            // DELETE /api/cart/items/{variationId}
            if ($method === 'DELETE' && preg_match('#^/api/cart/items/(\d+)$#', $uri, $m)) {
                $cart = new \App\controllers\CartController();
                $res  = $cart->removeItem((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 200);
                break;
            }

            // PUT /api/addresses/{id}
            if ($method === 'PUT' && preg_match('#^/api/addresses/(\d+)$#', $uri, $m)) {
                $ctl = new \App\controllers\AddressController();
                $res = $ctl->update((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 200);
                break;
            }

            // DELETE /api/addresses/{id}
            if ($method === 'DELETE' && preg_match('#^/api/addresses/(\d+)$#', $uri, $m)) {
                $ctl = new \App\controllers\AddressController();
                $res = $ctl->delete((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 200);
                break;
            }

            // GET /api/products/{id}/reviews
            if ($method === 'GET' && preg_match('#^/api/products/(\d+)/reviews$#', $uri, $m)) {
                $ctl = new \App\controllers\ReviewController();
                $res = $ctl->listForProduct((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 200);
                break;
            }

            // POST /api/products/{id}/reviews
            if ($method === 'POST' && preg_match('#^/api/products/(\d+)/reviews$#', $uri, $m)) {
                $ctl = new \App\controllers\ReviewController();
                $res = $ctl->create((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 201);
                break;
            }

            // GET /api/orders/{id}  |  POST /api/orders/{id}/pay
            if (preg_match('#^/api/orders/(\d+)(/pay)?$#', $uri, $m)) {
                $ctl = new \App\controllers\OrderController();
                $orderId = (int)$m[1];
                if ($method === 'GET' && empty($m[2])) {
                    $res = $ctl->show($orderId);
                    json_response($res['body'] ?? [], $res['status'] ?? 200);
                    break;
                }
                if ($method === 'POST' && $m[2] === '/pay') {
                    $res = $ctl->payMock($orderId);
                    json_response($res['body'] ?? [], $res['status'] ?? 200);
                    break;
                }
            }

            // PATCH /api/reviews/{id}
            if ($method === 'PATCH' && preg_match('#^/api/reviews/(\d+)$#', $uri, $m)) {
                $ctl = new \App\controllers\ReviewController();
                $res = $ctl->moderate((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 200);
                break;
            }

            // DELETE /api/reviews/{id}
            if ($method === 'DELETE' && preg_match('#^/api/reviews/(\d+)$#', $uri, $m)) {
                $ctl = new \App\controllers\ReviewController();
                $res = $ctl->delete((int)$m[1]);
                json_response($res['body'] ?? [], $res['status'] ?? 200);
                break;
            }


            // 404 si rien ne matche
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
