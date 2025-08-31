<?php

declare(strict_types=1);

namespace App\controllers;

use App\core\Database;
use PDO;

final class CategoryController
{
    public function list(): array
    {
        $sql = "SELECT id_categorie AS id, nom, slug, image_url
                FROM categories
                WHERE is_active = 1
                ORDER BY nom ASC";
        $stmt = Database::pdo()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
