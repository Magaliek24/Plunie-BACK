<?php
require_once '../includes/security.php';


session_start();

// Configuration pour Maildev en local
if ($_SERVER['SERVER_NAME'] === 'localhost') {
    ini_set('SMTP', 'localhost');
    ini_set('smtp_port', '1025');
    ini_set('sendmail_from', 'noreply@plunie.fr');
}

// Activation de l'affichage des erreurs pour déboguer
error_reporting(E_ALL);
ini_set('display_errors', 1);


// 1. Vérification CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('Erreur de sécurité');
}

// 2. Vérification honeypot
if (!empty($_POST['website'])) {
    // Redirection silencieuse pour tromper les bots
    header('Location: /front/pages/contact_merci.php');
    exit();
}

// 3. Validation et nettoyage
$prenom = htmlspecialchars(trim($_POST['prenom']), ENT_QUOTES, 'UTF-8');
$nom = htmlspecialchars(trim($_POST['nom']), ENT_QUOTES, 'UTF-8');
$email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
$message = htmlspecialchars(trim($_POST['message']), ENT_QUOTES, 'UTF-8');

if (!$email) {
    die('Email invalide');
}

// 4. Protection contre l'injection d'en-têtes
if (preg_match("/[\r\n]/", $email) || preg_match("/[\r\n]/", $nom)) {
    die('Données invalides');
}

// 5. Limite de taux 
if (isset($_SESSION['last_submit']) && time() - $_SESSION['last_submit'] < 60) {
    die('Merci de patienter avant de renvoyer un message');
}
$_SESSION['last_submit'] = time();

// 6. Envoi email sécurisé
$to = 'contact@plunie.fr';
$subject = 'Contact depuis le site Plunie';

$headers = "From: noreply@plunie.fr\r\n";
$headers .= "Reply-To: " . $email . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$email_message = "Nouveau message en provenance du formulaire de contact:\n\n";
$email_message .= "Nom: $nom\n";
$email_message .= "Prénom: $prenom\n";
$email_message .= "Email: $email\n";
$email_message .= "Message:\n";
$email_message .= "-------------------\n";
$email_message .= $message;

// Debug temporaire pour voir le message
echo "<pre>";
echo "To: $to\n";
echo "Subject: $subject\n";
echo "Headers: $headers\n";
echo "Message:\n$email_message";
echo "</pre>";

// Envoi du mail
$mail_sent = mail($to, $subject, $email_message, $headers);

if ($mail_sent) {
    echo "Mail envoyé avec succès!";
    // header('Location: /front/pages/contact_merci.php');
} else {
    $error = error_get_last();
    echo "Erreur d'envoi : ";
    print_r($error);
}
exit();

// 7. Si tu stockes en BDD 
// if ($pdo) {
//     $stmt = $pdo->prepare("INSERT INTO contacts (prenom, nom, email, message, ip, date_envoi) VALUES (?, ?, ?, ?, ?, NOW())");
//     $stmt->execute([$prenom, $nom, $email, $message, $_SERVER['REMOTE_ADDR']]);
// }
