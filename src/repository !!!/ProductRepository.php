<?php

declare(strict_types=1);

namespace App\repository;

use App\core\Database;
use App\model\Product;
use PDO;

final class ProductRepository
{
    /**
     * Renvoie [items, total]
     */
    public function search(array $query, int $page = 1, int $limit = 12): array
    {
        $pdo    = Database::pdo();
        $page   = max(1, $page);
        $limit  = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;

        $where   = ["p.is_active = 1"];
        $params  = [];

        if (!empty($query['category_id'])) {
            $where[]            = "p.id_categorie = :cat_id";
            $params[':cat_id']  = (int)$query['category_id'];
        }

        if (!empty($query['category']) && empty($query['category_id'])) {
            $where[]              = "p.id_categorie = (SELECT id_categorie FROM categories WHERE slug = :cat_slug)";
            $params[':cat_slug']  = (string)$query['category'];
        }

        if (!empty($query['q'])) {
            $where[]         = "(p.nom LIKE :q OR p.description_courte LIKE :q OR p.description_longue LIKE :q)";
            $params[':q']    = '%' . $query['q'] . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // total
        $countSql = "SELECT COUNT(*) FROM produits p {$whereSql}";
        $stmt = $pdo->prepare($countSql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $total = (int)$stmt->fetchColumn();

        // data
        $sql = "
        SELECT
          p.id_produit    AS id,
          p.nom,
          p.slug,
          p.prix,
          p.prix_promo,
          p.id_categorie  AS category_id,
          c.nom           AS category_name,
          (
            SELECT ip.image_url
            FROM images_produits ip
            WHERE ip.id_produit = p.id_produit
            ORDER BY ip.is_primary DESC, ip.ordre ASC, ip.id_image_produit ASC
            LIMIT 1
          ) AS image,
          COALESCE((
            SELECT SUM(v.stock) FROM variations_produits v
            WHERE v.id_produit = p.id_produit AND v.is_active = 1
          ), 0) AS stock_total,
          (SELECT ROUND(AVG(a.note),2) FROM avis_produits a
           WHERE a.id_produit = p.id_produit AND a.statut = 'approuve') AS note_moyenne,
          (SELECT COUNT(*) FROM avis_produits a2
           WHERE a2.id_produit = p.id_produit AND a2.statut = 'approuve') AS nb_avis
        FROM produits p
        JOIN categories c ON c.id_categorie = p.id_categorie
        {$whereSql}
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [$items, $total];
    }

    /**
     * Détail : renvoie Product (model) ou null
     */
    public function findWithDetails(int $id): ?Product
    {
        $pdo = Database::pdo();

        $sql = "SELECT 
                  p.id_produit   AS id,
                  p.id_categorie AS category_id,
                  c.nom          AS category_name,
                  p.nom, p.slug,
                  p.description_courte, p.description_longue,
                  p.prix, p.prix_promo, p.is_active,
                  p.created_at, p.updated_at
                FROM produits p
                JOIN categories c ON c.id_categorie = p.id_categorie
                WHERE p.id_produit = :id AND p.is_active = 1
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $product = Product::fromRow($row);

        // Images
        $imgSql = "SELECT image_url, alt_text, is_primary, ordre
                   FROM images_produits
                   WHERE id_produit = :id
                   ORDER BY is_primary DESC, ordre ASC, id_image_produit ASC";
        $stmt = $pdo->prepare($imgSql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $product->images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Variations
        $varSql = "SELECT 
                     v.id_variation AS id,
                     v.taille, v.couleur, v.sku, v.stock, v.is_active,
                     COALESCE(v.prix_variation, p.prix) AS prix_effectif,
                     v.image_url
                   FROM variations_produits v
                   JOIN produits p ON p.id_produit = v.id_produit
                   WHERE v.id_produit = :id
                   ORDER BY CAST(v.taille AS UNSIGNED), v.taille, v.couleur";
        $stmt = $pdo->prepare($varSql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $product->variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Rating
        $noteSql = "SELECT 
                      ROUND(AVG(CASE WHEN statut='approuve' THEN note END), 2) AS note_moyenne,
                      COUNT(CASE WHEN statut='approuve' THEN 1 END)          AS nb_avis
                    FROM avis_produits WHERE id_produit = :id";
        $stmt = $pdo->prepare($noteSql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $rating = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['note_moyenne' => null, 'nb_avis' => 0];
        $product->note_moyenne = $rating['note_moyenne'] !== null ? (float)$rating['note_moyenne'] : null;
        $product->nb_avis      = (int)$rating['nb_avis'];

        return $product;
    }


    public function list(int $limit = 12, int $page = 1): array
    {
        $pdo    = Database::pdo();
        $page   = max(1, $page);
        $limit  = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;

        $sql = "
            SELECT 
                p.id_produit AS id,
                p.nom,
                p.slug,
                p.prix,
                p.prix_promo,
                c.nom AS category_name,
                (
                    SELECT ip.image_url
                    FROM images_produits ip
                    WHERE ip.id_produit = p.id_produit
                    ORDER BY ip.is_primary DESC, ip.ordre ASC, ip.id_image_produit ASC
                    LIMIT 1
                ) AS image,
                COALESCE((
                    SELECT SUM(v.stock)
                    FROM variations_produits v
                    WHERE v.id_produit = p.id_produit
                    AND v.is_active = 1
                ), 0) AS stock_total
            FROM produits p
            JOIN categories c ON c.id_categorie = p.id_categorie
            WHERE p.is_active = 1
            ORDER BY p.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total pour la pagination
        $countSql = "SELECT COUNT(*) FROM produits p WHERE p.is_active = 1";
        $total = (int)$pdo->query($countSql)->fetchColumn();

        return [$items, $total];
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $st = Database::pdo()->prepare("
            SELECT p.id_produit AS id, p.nom, p.description, p.prix
            FROM produits p
            WHERE p.id_produit = :id
            LIMIT 1
        ");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
