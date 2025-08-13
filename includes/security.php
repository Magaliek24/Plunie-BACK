<?php
session_start();

// Génération token CSRF
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Vérification CSRF
function verify_csrf_token($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
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
