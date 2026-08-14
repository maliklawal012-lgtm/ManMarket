<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;

/**
 * Client SMTP minimal, en PHP pur (socket brut), sans dependance externe —
 * coherent avec le reste du projet (aucun Composer). Supporte STARTTLS
 * (port 587, le plus courant) et TLS implicite (port 465).
 *
 * Toujours "best effort" : send() ne leve jamais d'exception, retourne
 * simplement false en cas d'echec (connexion, auth, rejet serveur...).
 * Une notification ne doit jamais faire echouer l'action metier qui la
 * declenche (creation de commande, retrait, remboursement...).
 */
final class SmtpMailer
{
    private const CONNECT_TIMEOUT = 5;
    private const READ_TIMEOUT = 10;

    public function isConfigured(): bool
    {
        return \SMTP_HOST !== '';
    }

    public function send(string $toEmail, string $toName, string $subject, string $body): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $socket = null;

        try {
            $socket = $this->connect();
            if ($socket === null) {
                return false;
            }

            $this->expect($socket, 220);
            $this->command($socket, 'EHLO manmarket.ci', 250);

            if (\SMTP_ENCRYPTION === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    Logger::error('notifications', 'SMTP : echec STARTTLS');

                    return false;
                }
                $this->command($socket, 'EHLO manmarket.ci', 250);
            }

            if (\SMTP_USERNAME !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode(\SMTP_USERNAME), 334);
                $this->command($socket, base64_encode(\SMTP_PASSWORD), 235);
            }

            $this->command($socket, 'MAIL FROM:<' . \SMTP_FROM_EMAIL . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', 250);
            $this->command($socket, 'DATA', 354);

            $headers = [
                'From: ' . $this->encodeHeader(\SMTP_FROM_NAME) . ' <' . \SMTP_FROM_EMAIL . '>',
                'To: ' . $this->encodeHeader($toName) . ' <' . $toEmail . '>',
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'Date: ' . date('r'),
            ];

            $escapedBody = preg_replace('/^\./m', '..', $body) ?? $body;
            $message = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.";

            $this->command($socket, $message, 250);
            $this->command($socket, 'QUIT', 221);

            return true;
        } catch (\Throwable $e) {
            Logger::error('notifications', 'SMTP : envoi echoue', ['to' => $toEmail, 'error' => $e->getMessage()]);

            return false;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** @return resource|null */
    private function connect()
    {
        $transport = \SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : '';
        $target = $transport . \SMTP_HOST . ':' . \SMTP_PORT;

        $socket = @stream_socket_client($target, $errno, $errstr, self::CONNECT_TIMEOUT);
        if ($socket === false) {
            Logger::error('notifications', 'SMTP : connexion impossible', ['target' => $target, 'error' => $errstr]);

            return null;
        }

        stream_set_timeout($socket, self::READ_TIMEOUT);

        return $socket;
    }

    /** @param resource $socket */
    private function command($socket, string $line, int $expectedCode): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private function expect($socket, int $expectedCode): void
    {
        $response = '';
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                throw new \RuntimeException('Connexion SMTP interrompue (attendu code ' . $expectedCode . ')');
            }
            $response = $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException("Reponse SMTP inattendue : {$response} (attendu {$expectedCode})");
        }
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
