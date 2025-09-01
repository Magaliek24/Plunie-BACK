<?php

declare(strict_types=1);

namespace App\controllers;

use App\core\Database;
use PDO;

final class AuthController
{
    public function register(): array
    {
        require_once __DIR__ . '/../core/Security.php';
        $data = $this->readJson();
        $this->requireCsrf($data);

        $nom     = trim((string)($data['nom'] ?? ''));
        $prenom  = trim((string)($data['prenom'] ?? ''));
        $email   = strtolower(trim((string)($data['email'] ?? '')));
        $passRaw = (string)($data['password'] ?? '');

        $errors = [];
        if ($nom === '')     $errors['nom'] = 'Nom requis';
        if ($prenom === '')  $errors['prenom'] = 'Prénom requis';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email invalide';
        if (strlen($passRaw) < 8) $errors['password'] = '8 caractères minimum';

        if ($errors) {
            return $this->resp(422, ['ok' => false, 'errors' => $errors]);
        }

        $pdo = Database::pdo();

        // email unique ?
        $st = $pdo->prepare("SELECT 1 FROM utilisateurs WHERE email = :e LIMIT 1");
        $st->execute([':e' => $email]);
        if ($st->fetchColumn()) {
            return $this->resp(409, ['ok' => false, 'error' => 'email_taken']);
        }

        $hash = password_hash($passRaw, PASSWORD_DEFAULT);

        $st = $pdo->prepare("
            INSERT INTO utilisateurs (nom, prenom, email, password_hash, role, is_active)
            VALUES (:nom, :prenom, :email, :hash, 'client', 1)
        ");
        $st->execute([
            ':nom'    => $nom,
            ':prenom' => $prenom,
            ':email'  => $email,
            ':hash'   => $hash,
        ]);

        $userId = (int)$pdo->lastInsertId();
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'client';

        return $this->resp(201, [
            'ok'   => true,
            'user' => ['id' => $userId, 'email' => $email, 'nom' => $nom, 'prenom' => $prenom, 'role' => 'client']
        ]);
    }

    public function login(): array
    {
        require_once __DIR__ . '/../core/Security.php';
        $data = $this->readJson();
        $this->requireCsrf($data);

        $email   = strtolower(trim((string)($data['email'] ?? '')));
        $passRaw = (string)($data['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $passRaw === '') {
            return $this->resp(400, ['ok' => false, 'error' => 'bad_credentials']);
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT id_utilisateur, nom, prenom, email, password_hash, role, is_active
                             FROM utilisateurs WHERE email = :e LIMIT 1");
        $st->execute([':e' => $email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if (!$u || !password_verify($passRaw, $u['password_hash'])) {
            return $this->resp(401, ['ok' => false, 'error' => 'bad_credentials']);
        }
        if ((int)$u['is_active'] !== 1) {
            return $this->resp(403, ['ok' => false, 'error' => 'inactive']);
        }

        // OK: on connecte l’utilisateur
        $_SESSION['user_id']   = (int)$u['id_utilisateur'];
        $_SESSION['user_role'] = (string)$u['role'];

        // Fusion panier invité -> user
        $this->mergeGuestCart(Database::pdo(), (int)$u['id_utilisateur']);

        return $this->resp(200, [
            'ok'   => true,
            'user' => [
                'id'     => (int)$u['id_utilisateur'],
                'email'  => $u['email'],
                'nom'    => $u['nom'],
                'prenom' => $u['prenom'],
                'role'   => $u['role'],
            ],
        ]);
    }

    public function me(): array
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return $this->resp(401, ['ok' => false, 'error' => 'unauthenticated']);
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT id_utilisateur, nom, prenom, email, role FROM utilisateurs WHERE id_utilisateur = :id LIMIT 1");
        $st->execute([':id' => $uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if (!$u) {
            // session orpheline
            unset($_SESSION['user_id'], $_SESSION['user_role']);
            return $this->resp(401, ['ok' => false, 'error' => 'unauthenticated']);
        }

        return $this->resp(200, [
            'ok'   => true,
            'user' => [
                'id'     => (int)$u['id_utilisateur'],
                'email'  => $u['email'],
                'nom'    => $u['nom'],
                'prenom' => $u['prenom'],
                'role'   => $u['role'],
            ],
        ]);
    }

    public function logout(): array
    {
        require_once __DIR__ . '/../core/Security.php';
        $data = $this->readJson();
        $this->requireCsrf($data);

        // On garde d’autres cookies (cart), on déconnecte seulement l’user
        unset($_SESSION['user_id'], $_SESSION['user_role']);
        session_regenerate_id(true);

        return $this->resp(200, ['ok' => true]);
    }

    /* ---------------- helpers ---------------- */

    private function readJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function requireCsrf(array $data): void
    {
        $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $tok = (string)($data['csrf_token'] ?? $hdr);
        if (!function_exists('verify_csrf_token') || !verify_csrf_token($tok)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
            exit;
        }
    }

    private function resp(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }


    private function mergeGuestCart(PDO $pdo, int $userId): void
    {
        $cookieName = 'cart_token';
        $guestToken = $_COOKIE[$cookieName] ?? null;
        if (!$guestToken) return;

        try {
            $pdo->beginTransaction();

            // Panier invité à partir du token
            $st = $pdo->prepare("SELECT id_panier FROM paniers WHERE token = :t LIMIT 1");
            $st->execute([':t' => $guestToken]);
            $guestCartId = (int)($st->fetchColumn() ?: 0);
            if ($guestCartId <= 0) {
                $pdo->rollBack();
                // On supprime quand même le cookie périmé
                setcookie($cookieName, '', time() - 3600, '/');
                return;
            }

            // Panier utilisateur existant ?
            $st = $pdo->prepare("
                SELECT id_panier 
                FROM paniers 
                WHERE id_utilisateur = :u 
                ORDER BY updated_at DESC, id_panier DESC 
                LIMIT 1
            ");
            $st->execute([':u' => $userId]);
            $userCartId = (int)($st->fetchColumn() ?: 0);

            if ($userCartId <= 0) {
                // Pas de panier user : on "adopte" le panier invité
                $st = $pdo->prepare("UPDATE paniers SET id_utilisateur = :u, token = NULL WHERE id_panier = :g");
                $st->execute([':u' => $userId, ':g' => $guestCartId]);
            } else {
                // Merge des lignes (ON DUPLICATE KEY sur (id_panier, id_variation))
                $st = $pdo->prepare("
                    INSERT INTO panier_articles (id_panier, id_variation, quantite)
                    SELECT :userCart, id_variation, quantite
                    FROM panier_articles
                    WHERE id_panier = :guestCart
                    ON DUPLICATE KEY UPDATE quantite = LEAST(panier_articles.quantite + VALUES(quantite), 999)
                ");
                $st->execute([':userCart' => $userCartId, ':guestCart' => $guestCartId]);

                // Supprime le panier invité (CASCADE efface ses lignes restantes)
                $st = $pdo->prepare("DELETE FROM paniers WHERE id_panier = :g");
                $st->execute([':g' => $guestCartId]);
            }

            $pdo->commit();

            // Invalide le cookie invité (on travaille désormais en mode user)
            setcookie($cookieName, '', time() - 3600, '/');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('mergeGuestCart error: ' . $e->getMessage());
            // On ne bloque pas le login si la fusion panique : juste log
        }
    }
}
