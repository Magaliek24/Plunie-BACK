<?php

declare(strict_types=1);

namespace App\controllers;

use App\core\Database;
use PDO;

final class ProductController
{
    /**
     * Liste paginée avec filtres simples: ?page=1&limit=12&category=slug&q=mot
     */
    public function list(array $query = []): array
    {
        $pdo = Database::pdo();
        $page  = max(1, (int)($query['page']  ?? 1));
        $limit = max(1, min(50, (int)($query['limit'] ?? 12)));
        $offset = ($page - 1) * $limit;

        $where = ["p.is_active = 1"];
        $params = [];

        // Filtre par ID de catégorie
        if (!empty($query['category_id'])) {
            $where[] = "p.id_categorie = :cat_id";
            $params[':cat_id'] = (int)$query['category_id'];
        }

        // OU filtre par slug de catégorie
        if (!empty($query['category']) && empty($query['category_id'])) {
            /// si le slug n'existe pas, ça renverra 0 produit (comportement OK)
            $where[] = "p.id_categorie = (SELECT id_categorie FROM categories WHERE slug = :cat_slug)";
            $params[':cat_slug'] = (string)$query['category'];
        }

        // Recherche texte (simple LIKE)
        if (!empty($query['q'])) {
            $where[]         = "(p.nom LIKE :q OR p.description_courte LIKE :q OR p.description_longue LIKE :q)";
            $params[':q']    = '%' . $query['q'] . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // total pour pagination
        $countSql = "SELECT COUNT(*) FROM produits p {$whereSql}";
        $stmt = $pdo->prepare($countSql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $total = (int)$stmt->fetchColumn();

        // data (image principale, stock total, note moyenne, nb avis, nom catégorie)
        $sql = "
        SELECT
          p.id_produit    AS id,
          p.nom,
          p.slug,
          p.prix,
          p.prix_promo,
          p.id_categorie  AS category_id,
          c. nom AS category_name,
          -- Image principale: priorité à is_primary puis ordre
            (
                SELECT ip.image_url
                FROM images_produits ip
                WHERE ip.id_produit = p.id_produit
                ORDER BY ip.is_primary DESC, ip.ordre ASC, ip.id_image_produit ASC
                LIMIT 1
            ) AS image,
          -- Stock total (variations actives)
          COALESCE((
            SELECT SUM(v.stock) FROM variations_produits v
            WHERE v.id_produit = p.id_produit AND v.is_active = 1
          ), 0) AS stock_total,
          -- Note moyenne & nb d'avis approuvés
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

        return [
            'items' => $items,
            'meta'  => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total
            ]
        ];
    }

    /**
     * Détail d’un produit + images + variations + avis approuvés (paginés).
     */
    public function show(int $id): array
    {
        $pdo = Database::pdo();

        // Produit actif + nom catégorie
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
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            http_response_code(404);
            return ['error' => 'Produit introuvable'];
        }

        // Images (is_primary d'abord, puis ordre)
        $imgSql = "SELECT image_url, alt_text, is_primary, ordre
                   FROM images_produits
                   WHERE id_produit = :id
                   ORDER BY is_primary DESC, ordre ASC, id_image_produit ASC";
        $stmt = $pdo->prepare($imgSql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Variations + prix effectif 
        $varSql = "SELECT 
                     v.id_variation AS id,
                     v.taille, v.couleur, v.sku, v.stock, v.is_active,
                     COALESCE(v.prix_variation, p.prix) AS prix_effectif,
                     v.image_url
                   FROM variations_produits v
                   JOIN produits p ON p.id_produit = v.id_produit
                   WHERE v.id_produit = :id
                   ORDER BY CAST(v.taille AS UNSIGNED), v.taille,v.couleur";
        $stmt = $pdo->prepare($varSql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Note moyenne + nb avis
        $noteSql = "SELECT 
                      ROUND(AVG(CASE WHEN statut='approuve' THEN note END), 2) AS note_moyenne,
                      COUNT(CASE WHEN statut='approuve' THEN 1 END)          AS nb_avis
                    FROM avis_produits WHERE id_produit = :id";
        $stmt = $pdo->prepare($noteSql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $rating = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['note_moyenne' => null, 'nb_avis' => 0];

        return [
            'product'  => $product,
            'images'   => $images,
            'variants' => $variants,
            'rating'   => $rating,
        ];
    }
}
