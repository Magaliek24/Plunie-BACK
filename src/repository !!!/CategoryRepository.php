<?php

declare(strict_types=1);

namespace App\repository;

use App\core\Database;
use PDO;

final class CategoryRepository
{
    public function all(): array
    {
        $st = Database::pdo()->query("
            SELECT id_categorie AS id, nom 
            FROM categories 
            ORDER BY nom
        ");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
