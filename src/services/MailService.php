<?php

namespace App\Services;

class MailService
{
    private string $from;

    public function __construct(string $from = 'noreply@plunie.fr')
    {
        $this->from = $from;

        // Config en local (MailDev)
        if ($_SERVER['SERVER_NAME'] === 'localhost') {
            ini_set('SMTP', 'localhost');
            ini_set('smtp_port', '1025');
            ini_set('sendmail_from', $this->from);
        }
    }

    public function send(string $to, string $subject, string $message, ?string $replyTo = null): bool
    {
        $headers = "From: {$this->from}\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: $replyTo\r\n";
        }
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        return mail($to, $subject, $message, $headers);
    }
}
