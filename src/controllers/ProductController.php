<?php

declare(strict_types=1);

namespace App\controllers;

use App\repository\ProductRepository;
use PDO;

final class ProductController
{
    public function list(array $query = []): array
    {
        $page  = max(1, (int)($query['page']  ?? 1));
        $limit = max(1, min(50, (int)($query['limit'] ?? 12)));

        $repo = new ProductRepository();
        [$items, $total] = $repo->list($limit, $page);

        return [
            'items' => $items,
            'meta'  => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total
            ]
        ];
    }

    public function show(int $id): array
    {
        $repo = new ProductRepository();
        $product = $repo->findWithDetails($id);

        if (!$product) {
            http_response_code(404);
            return ['error' => 'Produit introuvable'];
        }

        return $product->toArray();
    }
}
