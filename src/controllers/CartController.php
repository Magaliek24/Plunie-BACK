<?php

declare(strict_types=1);

namespace App\controllers;

use App\core\Database;
use PDO;

final class CartController
{
    /** Nom du cookie pour les invités */
    private const CART_COOKIE = 'cart_token';

    /** Lecture du panier courant (guest via cookie, ou user plus tard) */
    public function get(): array
    {
        $pdo = Database::pdo();
        $cartId = $this->ensureCart($pdo);
        return $this->snapshot($pdo, $cartId);
    }

    /** Ajout d’un article {variation_id, qty} (JSON) */
    public function addItem(): array
    {
        require_once __DIR__ . '/../core/Security.php';
        $body = $this->readJson();
        $this->requireCsrf($body);

        $variationId = (int)($body['variation_id'] ?? 0);
        $qty         = max(1, (int)($body['qty'] ?? 1));
        if ($variationId <= 0) {
            return $this->resp(400, ['ok' => false, 'error' => 'bad_request', 'message' => 'variation_id manquant']);
        }

        $pdo = Database::pdo();
        $cartId = $this->ensureCart($pdo);

        // Vérifier que la variation existe
        $st = $pdo->prepare("SELECT id_produit, stock FROM variations_produits WHERE id_variation=:v AND is_active=1");
        $st->execute([':v' => $variationId]);
        $var = $st->fetch(PDO::FETCH_ASSOC);
        if (!$var) {
            return $this->resp(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Variation inconnue']);
        }

        // Upsert (unique key: id_panier + id_variation)
        $st = $pdo->prepare("
            INSERT INTO panier_articles (id_panier, id_variation, quantite)
            VALUES (:c, :v, :q)
            ON DUPLICATE KEY UPDATE quantite = LEAST(quantite + VALUES(quantite), 999)
        ");
        $st->execute([':c' => $cartId, ':v' => $variationId, ':q' => $qty]);

        return $this->snapshot($pdo, $cartId, 200);
    }

    /** PATCH /api/cart/items/{variationId}  Body: {qty} (fixe la quantité, <=0 = suppression) */
    public function updateItem(int $variationId): array
    {
        require_once __DIR__ . '/../core/Security.php';
        $body = $this->readJson();
        $this->requireCsrf($body);

        $pdo = Database::pdo();
        $cartId = $this->ensureCart($pdo);

        $qty = (int)($body['qty'] ?? 0);
        if ($qty <= 0) {
            $st = $pdo->prepare("DELETE FROM panier_articles WHERE id_panier=:c AND id_variation=:v");
            $st->execute([':c' => $cartId, ':v' => $variationId]);
            return $this->snapshot($pdo, $cartId, 200);
        }

        // Upsert pour fixer la quantité
        $st = $pdo->prepare("
            INSERT INTO panier_articles (id_panier, id_variation, quantite)
            VALUES (:c, :v, :q)
            ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)
        ");
        $st->execute([':c' => $cartId, ':v' => $variationId, ':q' => min(999, $qty)]);

        return $this->snapshot($pdo, $cartId, 200);
    }

    /** DELETE /api/cart/items/{variationId} */
    public function removeItem(int $variationId): array
    {
        require_once __DIR__ . '/../core/Security.php';
        $body = $this->readJson(); // pour récupérer le csrf si tu veux l’envoyer en JSON
        $this->requireCsrf($body);

        $pdo = Database::pdo();
        $cartId = $this->ensureCart($pdo);

        $st = $pdo->prepare("DELETE FROM panier_articles WHERE id_panier=:c AND id_variation=:v");
        $st->execute([':c' => $cartId, ':v' => $variationId]);

        return $this->snapshot($pdo, $cartId, 200);
    }

    /** DELETE /api/cart  (vider le panier) */
    public function clear(): array
    {
        require_once __DIR__ . '/../core/Security.php';
        $body = $this->readJson();
        $this->requireCsrf($body);

        $pdo = Database::pdo();
        $cartId = $this->ensureCart($pdo);

        $pdo->prepare("DELETE FROM panier_articles WHERE id_panier=:c")->execute([':c' => $cartId]);

        return $this->snapshot($pdo, $cartId, 200);
    }

    /* ----------------------- helpers ----------------------- */

    private function readJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function requireCsrf(array $data): void
    {
        // accepte soit header X-CSRF-Token, soit champs JSON csrf_token
        $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $tok = (string)($data['csrf_token'] ?? $hdr);
        if (!function_exists('verify_csrf_token') || !verify_csrf_token($tok)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
            exit;
        }
    }

    /** crée/trouve le panier et gère le cookie invité */
    private function ensureCart(PDO $pdo): int
    {
        // 1) Si l'utilisateur est connecté, on travaille toujours avec SON panier
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $st = $pdo->prepare("
                SELECT id_panier 
                FROM paniers 
                WHERE id_utilisateur = :u 
                ORDER BY updated_at DESC, id_panier DESC 
                LIMIT 1
            ");
            $st->execute([':u' => $uid]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;

            // sinon on en crée un lié à l'utilisateur
            $st = $pdo->prepare("INSERT INTO paniers (id_utilisateur, token) VALUES (:u, NULL)");
            $st->execute([':u' => $uid]);
            return (int)$pdo->lastInsertId();
        }

        // 2) invité via cookie
        $token = $_COOKIE[self::CART_COOKIE] ?? null;

        if ($token) {
            $st = $pdo->prepare("SELECT id_panier FROM paniers WHERE token=:t LIMIT 1");
            $st->execute([':t' => $token]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) return $id;
        }

        // créer un token + panier invité
        $token = bin2hex(random_bytes(16)); // 32 hex chars
        $st = $pdo->prepare("INSERT INTO paniers (id_utilisateur, token) VALUES (NULL, :t)");
        $st->execute([':t' => $token]);
        $id = (int)$pdo->lastInsertId();

        // cookie 1 an
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        setcookie(self::CART_COOKIE, $token, [
            'expires'  => time() + 365 * 24 * 3600,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $id;
    }

    /** construit le snapshot du panier (items + totaux) */
    private function snapshot(PDO $pdo, int $cartId, int $status = 200): array
    {
        $sql = "
            SELECT
              pa.id_variation       AS variation_id,
              pa.quantite           AS qty,
              v.sku,
              v.taille,
              v.couleur,
              v.stock               AS stock_disponible,
              p.id_produit          AS product_id,
              p.nom                 AS product_name,
              COALESCE(v.prix_variation, p.prix) AS prix_unitaire,
              (
                SELECT image_url
                FROM images_produits i
                WHERE i.id_produit = p.id_produit
                ORDER BY i.is_primary DESC, i.ordre ASC, i.id_image_produit ASC
                LIMIT 1
              ) AS image,
              (pa.quantite * COALESCE(v.prix_variation, p.prix)) AS total_ligne
            FROM panier_articles pa
            JOIN variations_produits v ON v.id_variation = pa.id_variation
            JOIN produits p            ON p.id_produit   = v.id_produit
            WHERE pa.id_panier = :c
            ORDER BY p.nom ASC, v.taille ASC, v.couleur ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':c' => $cartId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);

        $totalQty = 0;
        $totalAmt = 0.0;
        foreach ($items as $it) {
            $totalQty += (int)$it['qty'];
            $totalAmt += (float)$it['total_ligne'];
        }

        return $this->resp($status, [
            'ok'    => true,
            'items' => $items,
            'totals' => [
                'count_items' => count($items),
                'total_qty'   => $totalQty,
                'total_amount' => round($totalAmt, 2)
            ]
        ]);
    }

    private function resp(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
