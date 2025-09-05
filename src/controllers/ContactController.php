<?php

declare(strict_types=1);

namespace App\controllers;

use App\services\MailService;

final class ContactController
{
    public function sendMessage(): void
    {
        // --- CSRF ---
        $token = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? $_POST['token'] ?? '');
        if (!function_exists('verify_csrf_token') || !verify_csrf_token($token)) {
            $this->redirectFront('/views/pages/contact.php', ['error' => 'csrf']);
        }

        // --- Honeypot ---
        if (trim((string)($_POST['website'] ?? $_POST['hp'] ?? '')) !== '') {
            // On fait comme si tout allait bien pour les bots
            $this->redirectFront('/views/pages/contact_merci.php', ['sent' => 1]);
        }

        // --- Anti-flood ---
        if (!function_exists('check_flood_protection') || !check_flood_protection(60, 'contact')) {
            $this->redirectFront('/views/pages/contact.php', ['error' => 'rate']);
        }


        // --- Champs ---
        $prenom   = trim((string)($_POST['prenom'] ?? ''));
        $nom      = trim((string)($_POST['nom'] ?? ''));
        $name     = trim((string)($_POST['name'] ?? ''));
        $fullName = $name !== '' ? $name : trim($prenom . ' ' . $nom);

        $emailRaw = (string)($_POST['email'] ?? '');
        // validate_email() renvoie bool → on garde la string séparément
        if (!function_exists('validate_email') || !validate_email($emailRaw)) {
            $this->redirectFront('/views/pages/contact.php', ['error' => 'email']);
        }
        $email = $emailRaw; // string validée
        $subject  = trim((string)($_POST['subject'] ?? ''));
        $message  = trim((string)($_POST['message'] ?? ''));

        // --- Validation ---
        if ($fullName === '' || $message === '') {
            $this->redirectFront('/views/pages/contact.php', ['error' => 'validation']);
        }

        if ($subject === '') {
            $subject = 'Contact depuis le site Plunie';
        }

        // --- Corps mail (on échappe au cas où c’est réinjecté en HTML quelque part) ------
        $body  = "Nouveau message du site Plunie:\n\n";
        $body .= "Nom complet: " . (function_exists('escape') ? escape($fullName) : $fullName) . "\n";
        $body .= "Email: "       . (function_exists('escape') ? escape($email)     : $email)     . "\n\n";
        $body .= "Message:\n"    . (function_exists('escape') ? escape($message)   : $message)   . "\n";

        // --- Envoi ---
        try {
            $mailer = new MailService(); // lit MAIL_HOST/MAIL_PORT depuis .env
            $sent   = $mailer->send("contact@plunie.fr", $subject, $body, $email);
            if (!$sent) {
                $this->redirectFront('/views/pages/contact.php', ['error' => 'mail']);
            }
        } catch (\Throwable $e) {
            error_log('Contact mail error: ' . $e->getMessage());
            $this->redirectFront('/views/pages/contact.php', ['error' => 'server']);
        }

        // --- Succès ---
        $this->redirectFront('/views/pages/contact_merci.php', ['sent' => 1]);
    }

    /* ---------- helpers ---------- */

    private function frontBase(): string
    {
        $base = rtrim($_ENV['FRONT_URL'] ?? 'http://localhost:8080', '/');
        return $base;
    }

    private function redirectFront(string $path, array $qs = [], int $status = 303): void
    {
        $url = $this->frontBase() . $path;
        if ($qs) $url .= '?' . http_build_query($qs);
        header('Location: ' . $url, true, $status);
        exit;
    }
}
