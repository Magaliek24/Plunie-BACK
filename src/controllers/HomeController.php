<?php

declare(strict_types=1);

namespace App\controllers;

final class HomeController
{
    public function index(): string
    {
        header('Content-Type: text/html; charset=utf-8');
        return '<h1>Plunie — backend OK</h1><p>Bienvenue 👋</p>';
    }
}
