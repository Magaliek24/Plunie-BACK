<?php

declare(strict_types=1);

use App\core\Router;
use App\controllers\HomeController;
use App\controllers\ContactController;

$router = new Router($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');

$router->get('/', [HomeController::class, 'index']);              // home
$router->post('/contact/send', [ContactController::class, 'sendMessage']); // contact

$router->run();
