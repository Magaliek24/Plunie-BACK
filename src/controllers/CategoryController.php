<?php

declare(strict_types=1);

namespace App\controllers;

use App\repository\CategoryRepository;

final class CategoryController
{
    public function list(): array
    {
        $repo = new CategoryRepository();
        return $repo->all();
    }
}
