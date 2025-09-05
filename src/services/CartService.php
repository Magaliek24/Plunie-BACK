<?php

declare(strict_types=1);

namespace App\services;

use PDO;

final class CartService
{
    public function __construct(private PDO $pdo) {}

    public function getUserCartId(int $uid): int
    {
        $st = $this->pdo->prepare("SELECT id_panier FROM paniers WHERE id_utilisateur = :uid ORDER BY id_panier DESC LIMIT 1");
        $st->execute([':uid' => $uid]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /**
     * Retourne les lignes nécessaires au checkout:
     * id_variation, quantite, stock, prix_unitaire, id_produit, nom
     */
    public function getCartLines(int $cartId): array
    {
        $sql = "
            SELECT 
              pa.id_variation, pa.quantite,
              v.stock,
              COALESCE(v.prix_variation, p.prix) AS prix_unitaire,
              p.id_produit, p.nom
            FROM panier_articles pa
            JOIN variations_produits v ON v.id_variation = pa.id_variation
            JOIN produits p            ON p.id_produit   = v.id_produit
            WHERE pa.id_panier = :pid
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':pid' => $cartId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clearCart(int $cartId): void
    {
        $this->pdo->prepare("DELETE FROM panier_articles WHERE id_panier = :pid")
            ->execute([':pid' => $cartId]);
    }
}
