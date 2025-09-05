<?php

declare(strict_types=1);

namespace App\controllers;

use App\core\Database;
use PDO;

final class ReviewController
{
    // GET /api/products/{id}/reviews
    public function listForProduct(int $productId): array
    {
        // Pagination via ?page & ?limit
        $page   = max(1, (int)($_GET['page']  ?? 1));
        $limit  = max(1, min(50, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;

        $pdo = Database::pdo();

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM avis_produits WHERE id_produit = :pid AND statut = 'approuve'");
        $cnt->execute([':pid' => $productId]);
        $total = (int)$cnt->fetchColumn();

        $st = $pdo->prepare("
            SELECT id_avis AS id, id_utilisateur, note, titre, commentaire, created_at
            FROM avis_produits
            WHERE id_produit = :pid AND statut = 'approuve'
            ORDER BY id_avis DESC
            LIMIT :limit OFFSET :offset
        ");
        $st->bindValue(':pid',    $productId, PDO::PARAM_INT);
        $st->bindValue(':limit',  $limit,     PDO::PARAM_INT);
        $st->bindValue(':offset', $offset,    PDO::PARAM_INT);
        $st->execute();

        return [
            'status' => 200,
            'body'   => [
                'ok'   => true,
                'items' => $st->fetchAll(PDO::FETCH_ASSOC),
                'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total]
            ]
        ];
    }

    // POST /api/products/{id}/reviews  (auth + csrf)
    public function create(int $productId): array
    {

        if (!isset($_SESSION['user_id'])) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthenticated']];
        }
        if (!function_exists('verify_csrf_token') || !verify_csrf_token(null)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'csrf_invalid']];
        }

        $uid  = (int)$_SESSION['user_id'];
        $note = (int)($_POST['note'] ?? 0);
        $titre = trim((string)($_POST['titre'] ?? ''));
        $commentaire = trim((string)($_POST['commentaire'] ?? ''));

        if ($note < 1 || $note > 5) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'invalid_note']];
        }

        $st = Database::pdo()->prepare("
            INSERT INTO avis_produits (id_produit, id_utilisateur, note, titre, commentaire, statut)
            VALUES (:pid, :uid, :n, :t, :c, 'en_attente')
        ");
        $st->execute([':pid' => $productId, ':uid' => $uid, ':n' => $note, ':t' => $titre, ':c' => $commentaire]);

        return ['status' => 201, 'body' => ['ok' => true, 'pending' => true]];
    }

    // PATCH /api/reviews/{id} {statut: approuve|rejete} (admin)
    public function moderate(int $reviewId): array
    {

        if (($_SESSION['user_role'] ?? 'client') !== 'admin') {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'forbidden']];
        }
        if (!function_exists('verify_csrf_token') || !verify_csrf_token(null)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'csrf_invalid']];
        }

        $statut = (string)($_POST['statut'] ?? '');
        if (!in_array($statut, ['approuve', 'rejete'], true)) {
            return ['status' => 422, 'body' => ['ok' => false, 'error' => 'bad_status']];
        }

        $st = Database::pdo()->prepare("UPDATE avis_produits SET statut = :s WHERE id_avis = :id");
        $st->execute([':s' => $statut, ':id' => $reviewId]);

        return ['status' => 200, 'body' => ['ok' => true]];
    }

    // DELETE /api/reviews/{id} – admin uniquement
    public function delete(int $reviewId): array
    {

        if (!isset($_SESSION['user_id'])) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthenticated']];
        }

        // Vérifier que c'est un admin
        if (($_SESSION['user_role'] ?? 'client') !== 'admin') {
            return ['status' => 403, 'body' => ['ok' => false, 'error' => 'forbidden']];
        }

        if (!verify_csrf_token(null)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'csrf_invalid']];
        }

        // Vérifier que l'avis existe
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM avis_produits WHERE id_avis = :id");
        $st->execute([':id' => $reviewId]);
        if (!$st->fetchColumn()) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'not_found']];
        }

        // Supprimer l'avis
        Database::pdo()->prepare("DELETE FROM avis_produits WHERE id_avis = :id")->execute([':id' => $reviewId]);

        return ['status' => 200, 'body' => ['ok' => true]];
    }
}
