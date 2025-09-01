<?php

declare(strict_types=1);

// Démarre la session seulement si pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Génération token CSRF
function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Vérification CSRF en acceptant :
// l’argument $token si fourni
// sinon le header X-CSRF-Token
// sinon $_POST['csrf_token']
// sinon le body JSON { "csrf_token": "..." }
function verify_csrf_token(?string $token = null): bool
{
    // Si rien fourni, on va le chercher dans la requête
    if ($token === null || $token === '') {
        // 1) Header prioritaire
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        // 2) Form (x-www-form-urlencoded / multipart)
        elseif (isset($_POST['csrf_token'])) {
            $token = (string)$_POST['csrf_token'];
        }
        // 3) JSON
        else {
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($ct, 'application/json') !== false) {
                $raw  = file_get_contents('php://input') ?: '';
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['csrf_token'])) {
                    $token = (string)$data['csrf_token'];
                }
            }
        }
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return (is_string($token) && $token !== '' &&
        is_string($sessionToken) && $sessionToken !== '' &&
        hash_equals($sessionToken, $token));
}

// Protection XSS
function escape($data)
{
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Validation email
function validate_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Anti-flood
function check_flood_protection($seconds = 60)
{
    if (isset($_SESSION['last_submit']) && time() - $_SESSION['last_submit'] < $seconds) {
        return false;
    }
    return true;
}
