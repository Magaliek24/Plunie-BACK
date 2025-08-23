<?php

declare(strict_types=1);

namespace App\controllers;

use App\services\MailService;

final class ContactController
{
    public function sendMessage(): void
    {
        // Fonctions utilitaires
        require_once __DIR__ . '/../core/Security.php';
        require_once __DIR__ . '/../services/MailService.php';

        // --- CSRF ---
        $token = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? $_POST['token'] ?? '');
        if (!verify_csrf_token($token)) {
            http_response_code(400);
            $this->respond(['ok' => false, 'error' => 'csrf_invalid']);
            return;
        }

        // --- Honeypot ---
        if (trim((string)($_POST['website'] ?? $_POST['hp'] ?? '')) !== '') {
            // On fait comme si c'était OK pour les bots
            $this->respond(['ok' => true]);
            return;
        }

        // --- Anti-flood ---
        if (!check_flood_protection(60)) {
            http_response_code(429);
            $this->respond(['ok' => false, 'error' => 'rate_limited']);
            return;
        }
        $_SESSION['last_submit'] = time();

        // --- Champs ---
        $prenom   = trim((string)($_POST['prenom'] ?? ''));
        $nom      = trim((string)($_POST['nom'] ?? ''));
        $name     = trim((string)($_POST['name'] ?? ''));
        $fullName = $name !== '' ? $name : trim($prenom . ' ' . $nom);

        $emailRaw = (string)($_POST['email'] ?? '');
        $email    = validate_email($emailRaw); // renvoie string|false selon ton Security.php
        $subject  = trim((string)($_POST['subject'] ?? ''));
        $message  = trim((string)($_POST['message'] ?? ''));

        // --- Validation ---
        $errors = [];
        if ($fullName === '') {
            $errors['name'] = 'Nom requis';
        }
        if (!$email) {
            $errors['email'] = 'Email invalide';
        }
        if ($message === '') {
            $errors['message'] = 'Message requis';
        }

        if ($errors) {
            http_response_code(422);
            $this->respond(['ok' => false, 'errors' => $errors]);
            return;
        }

        // --- Corps mail ---
        $to = 'contact@plunie.fr';
        if ($subject === '') {
            $subject = 'Contact depuis le site Plunie';
        }

        $body  = "Nouveau message du site Plunie:\n\n";
        $body .= "Nom complet: " . escape($fullName) . "\n";
        $body .= "Email: "       . escape($email)     . "\n\n";
        $body .= "Message:\n"    . escape($message)   . "\n";

        // --- Envoi ---
        try {
            $mailer = new MailService(); // lit MAIL_HOST/MAIL_PORT depuis .env
            $sent   = $mailer->send($to, $subject, $body, $email);
            if (!$sent) {
                throw new \RuntimeException('Envoi SMTP/mail() échoué');
            }
        } catch (\Throwable $e) {
            error_log('Contact mail error: ' . $e->getMessage());
            http_response_code(500);
            $this->respond(['ok' => false, 'error' => 'mail_failed']);
            return;
        }

        // --- Succès ---
        $this->respond(['ok' => true, 'message' => 'Votre message a bien été envoyé.'], '/views/pages/contact_merci.php');
    }

    private function respond(array $payload, ?string $redirectOnHtml = null): void
    {
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if (($payload['ok'] ?? false) && $redirectOnHtml) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
            header('Location: ' . $proto . '://' . $host . $redirectOnHtml);
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo ($payload['ok'] ?? false) ? 'OK' : 'Erreur';
    }
}
