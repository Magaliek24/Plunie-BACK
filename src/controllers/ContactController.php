<?php

namespace App\Controllers;

use App\Services\MailService;


class ContactController
{
    public function sendMessage()
    {
        // Configuration pour Mailpit dans Docker
        ini_set('SMTP', 'plunie_mail');
        ini_set('smtp_port', '1025');

        require_once __DIR__ . '/../core/Security.php';
        require_once __DIR__ . '/../services/MailService.php';

        // Debug - afficher les données reçues
        error_log("POST data: " . print_r($_POST, true));

        // Vérif CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            die('Erreur de sécurité');
        }

        // Honeypot
        if (!empty($_POST['website'])) {
            header('Location: /back/public/contact_merci.php');
            exit();
        }

        // Validation & nettoyage
        $prenom = escape(trim($_POST['prenom']));
        $nom = escape(trim($_POST['nom']));
        $email = validate_email($_POST['email']);
        $message = escape(trim($_POST['message']));

        if (!$email) {
            die('Email invalide');
        }

        // Anti-flood
        if (!check_flood_protection(60)) {
            die('Merci de patienter avant de renvoyer un message');
        }
        $_SESSION['last_submit'] = time();

        // Préparer mail
        $to = 'contact@plunie.fr';
        $subject = 'Contact depuis le site Plunie';
        $email_message = "Nouveau message du site Plunie:\n\n";
        $email_message .= "Nom: $nom\n";
        $email_message .= "Prénom: $prenom\n";
        $email_message .= "Email: $email\n\n";
        $email_message .= "Message:\n$message\n";

        // Debug
        error_log("Tentative d'envoi d'email à: $to");

        // Envoi via service
        try {
            $mailService = new MailService();
            if ($mailService->send($to, $subject, $email_message, $email)) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $redirect_url = $protocol . '://' . $host . '/views/pages/contact_merci.php';

                header('Location: ' . $redirect_url);
                exit();
            } else {
                error_log("MailService->send a retourné false");
                die("Erreur lors de l'envoi du message.");
            }
        } catch (\Exception $e) {
            error_log("Exception dans MailService: " . $e->getMessage());
            die("Erreur lors de l'envoi du message: " . $e->getMessage());
        }
    }
}
