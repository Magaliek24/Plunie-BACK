<?php

declare(strict_types=1);

// Démarre la session seulement si pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    // Active "Secure" uniquement si la requête ACTUELLE est en HTTPS 
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        str_starts_with($_ENV['APP_URL'] ?? '', 'https://');

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_secure', $https ? '1' : '0'); // <= ICI

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

// Vérification token CSRF
// Priorité: Header X-CSRF-Token > $_POST['csrf_token'].
// (JSON déjà injecté dans $_POST dans index.php)
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null || $token === '') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    }
    if (!is_string($token) || strlen($token) !== 64) {
        return false;
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}


// Protection XSS
function escape($data): string
{
    if ($data === null) return '';
    if (is_array($data) || is_object($data)) {
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
    } else {
        $data = (string)$data;
    }
    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Validation email
function validate_email(string $email): bool
{
    if (strlen($email) > 254) return false;
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Anti-flood
function check_flood_protection(int $seconds = 60, string $action = 'default'): bool
{
    $key = 'last_submit_' . $action;
    if (isset($_SESSION[$key]) && time() - $_SESSION[$key] < $seconds) return false;
    $_SESSION[$key] = time();
    return true;
}

function regenerate_session_id(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
}

function validate_password(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) $errors[] = 'Le mot de passe doit contenir au moins 8 caractères';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Le mot de passe doit contenir au moins une majuscule';
    if (!preg_match('/[a-z]/', $password)) $errors[] = 'Le mot de passe doit contenir au moins une minuscule';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Le mot de passe doit contenir au moins un chiffre';
    return $errors;
}

function sanitize_input(string $input, string $type = 'text'): string
{
    $input = trim($input);
    return match ($type) {
        'email'    => (string)filter_var($input, FILTER_SANITIZE_EMAIL),
        'url'      => (string)filter_var($input, FILTER_SANITIZE_URL),
        'int'      => (string)filter_var($input, FILTER_SANITIZE_NUMBER_INT),
        'alphanum' => preg_replace('/[^a-zA-Z0-9]/', '', $input) ?? '',
        default    => preg_replace('/[\x00-\x1F\x7F]/u', '', $input) ?? '',
    };
}

// --- Helpers user ---
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}
function current_user_role(): ?string
{
    return $_SESSION['user_role'] ?? 'client';
}
function is_admin(): bool
{
    return current_user_role() === 'admin';
}
function is_authenticated(): bool
{
    return current_user_id() !== null;
}
