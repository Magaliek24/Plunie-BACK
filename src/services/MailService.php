<?php

declare(strict_types=1);

namespace App\services;

final class MailService
{
    private string $from;
    private string $host;
    private int $port;

    public function __construct(string $from = 'noreply@plunie.fr', ?string $host = null, ?int $port = null)
    {
        $this->from = $from;
        $this->host = $host ?? ($_ENV['MAIL_HOST'] ?? 'plunie_mail');
        $this->port = $port ?? (int)($_ENV['MAIL_PORT'] ?? 1025);
    }

    public function send(string $to, string $subject, string $message, ?string $replyTo = null): bool
    {
        // Sécurité basique anti-injection dans les en-têtes
        $to      = $this->sanitizeAddress($to);
        $from    = $this->sanitizeAddress($this->from);
        $replyTo = $replyTo ? $this->sanitizeAddress($replyTo) : null;

        $fp = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 10);
        if (!$fp) {
            error_log("SMTP connect failed: $errno $errstr — fallback to mail()");
            return $this->fallbackMail($to, $subject, $message, $replyTo);
        }

        $read = function () use ($fp): string {
            $resp = '';
            while (($line = fgets($fp, 515)) !== false) {
                $resp .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') break; // fin réponse multi-ligne
            }
            return $resp;
        };
        $cmd = function (string $line) use ($fp, $read): string {
            fwrite($fp, $line . "\r\n");
            return $read();
        };
        $expect2xx3xx = function (string $resp, string $step): void {
            if (!preg_match('/^[23]\d{2}\b/', $resp)) {
                throw new \RuntimeException("SMTP error at {$step}: " . trim($resp));
            }
        };

        try {
            $banner = $read();
            $expect2xx3xx($banner, 'BANNER');

            $domain = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $expect2xx3xx($cmd("EHLO {$domain}"), 'EHLO');

            $expect2xx3xx($cmd("MAIL FROM:<{$from}>"), 'MAIL FROM');
            $expect2xx3xx($cmd("RCPT TO:<{$to}>"),     'RCPT TO');
            $expect2xx3xx($cmd("DATA"),                'DATA');

            // En-têtes
            $headers = [];
            $headers[] = "Date: " . date(DATE_RFC2822);
            $headers[] = "Message-ID: <" . bin2hex(random_bytes(8)) . "@{$domain}>";
            $headers[] = "From: <{$from}>";
            $headers[] = "To: <{$to}>";
            if ($replyTo) {
                $headers[] = "Reply-To: <{$replyTo}>";
            }
            $headers[] = "Subject: " . $this->encodeHeader($subject);
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: 8bit";
            $headerStr = implode("\r\n", $headers);

            // Corps : normalise CRLF + dot-stuffing
            $body = preg_replace("/\r\n|\r|\n/", "\r\n", $message ?? '');
            $body = preg_replace('/^\./m', '..', $body);

            fwrite($fp, $headerStr . "\r\n\r\n" . $body . "\r\n.\r\n");
            $expect2xx3xx($read(), 'END DATA');

            $cmd("QUIT");
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            error_log("SMTP send failed: " . $e->getMessage() . " — fallback to mail()");
            if (is_resource($fp)) {
                @fclose($fp);
            }
            return $this->fallbackMail($to, $subject, $message, $replyTo);
        }
    }

    private function fallbackMail(string $to, string $subject, string $message, ?string $replyTo): bool
    {
        // Garde le comportement d’origine si SMTP indisponible
        $headers = "From: {$this->from}\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: {$replyTo}\r\n";
        }
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        return @mail($to, $this->encodeHeader($subject), $message, $headers);
    }

    private function encodeHeader(string $text): string
    {
        return preg_match('/[^\x20-\x7E]/', $text)
            ? '=?UTF-8?B?' . base64_encode($text) . '?='
            : $text;
    }

    private function sanitizeAddress(string $addr): string
    {
        // Retire CR/LF et simples angles, on garde une adresse simple
        $addr = str_replace(["\r", "\n"], '', $addr);
        $addr = trim($addr, " \t<>");
        return $addr;
    }
}
