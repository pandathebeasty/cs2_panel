<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal email sender. Uses SMTP when configured in Settings, otherwise falls
 * back to PHP's mail(). Plain-text messages only (no attachments). Best-effort:
 * returns false on failure and never throws.
 *
 * SMTP settings (Settings keys):
 *   mail_smtp_host, mail_smtp_port, mail_smtp_user, mail_smtp_pass,
 *   mail_smtp_secure (''|'ssl'|'tls'), mail_from, mail_from_name
 */
final class Mailer
{
    /** True if an SMTP host is configured. Otherwise mail() is used. */
    public static function smtpConfigured(): bool
    {
        return trim((string) Settings::get('mail_smtp_host', '')) !== '';
    }

    public static function fromAddress(): string
    {
        $from = trim((string) Settings::get('mail_from', ''));
        if ($from !== '') {
            return $from;
        }
        // Fall back to a plausible no-reply at the site host.
        $host = parse_url((string) Settings::get('website_url', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return 'no-reply@' . preg_replace('/^www\./', '', (string) $host);
    }

    public static function fromName(): string
    {
        $name = trim((string) Settings::get('mail_from_name', ''));
        return $name !== '' ? $name : (string) Settings::get('site_name', 'CS2 Panel');
    }

       
                                                                 
       
    public static function send(string $to, string $subject, string $body): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            if (self::smtpConfigured()) {
                return self::sendSmtp($to, $subject, $body);
            }
            return self::sendMail($to, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[CS2Panel] Mailer send failed: ' . $e->getMessage());
            return false;
        }
    }

    /** PHP mail() fallback. */
    private static function sendMail(string $to, string $subject, string $body): bool
    {
        $from = self::fromAddress();
        $name = self::encodeHeader(self::fromName());
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $name . ' <' . $from . '>',
            'Reply-To: ' . $from,
        ];
        return @mail($to, self::encodeHeader($subject), $body, implode("\r\n", $headers));
    }

    /** Direct SMTP (no external library). Supports plain, SSL and STARTTLS. */
    private static function sendSmtp(string $to, string $subject, string $body): bool
    {
        $host   = trim((string) Settings::get('mail_smtp_host', ''));
        $port   = (int) (Settings::get('mail_smtp_port', '587') ?: 587);
        $user   = trim((string) Settings::get('mail_smtp_user', ''));
        $pass   = (string) Settings::get('mail_smtp_pass', '');
        $secure = strtolower(trim((string) Settings::get('mail_smtp_secure', '')));
        $from   = self::fromAddress();
        $fromName = self::fromName();

        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $timeout = 12;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            error_log('[CS2Panel] SMTP connect failed: ' . $errstr);
            return false;
        }
        stream_set_timeout($fp, $timeout);

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                // A space in the 4th char marks the last line of the reply.
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        $code = static fn(string $resp): int => (int) substr(trim($resp), 0, 3);

        $read(); // server greeting
        $ehloHost = parse_url((string) Settings::get('website_url', ''), PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $ehloHost);

        if ($secure === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) {
                fclose($fp);
                return false;
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                fclose($fp);
                return false;
            }
            $cmd('EHLO ' . $ehloHost);
        }

        if ($user !== '') {
            if ($code($cmd('AUTH LOGIN')) !== 334) { fclose($fp); return false; }
            if ($code($cmd(base64_encode($user))) !== 334) { fclose($fp); return false; }
            if ($code($cmd(base64_encode($pass))) !== 235) { fclose($fp); return false; }
        }

        if ($code($cmd('MAIL FROM:<' . $from . '>')) !== 250) { fclose($fp); return false; }
        if ($code($cmd('RCPT TO:<' . $to . '>')) >= 400) { fclose($fp); return false; }
        if ($code($cmd('DATA')) !== 354) { fclose($fp); return false; }

        $headers = 'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>' . "\r\n"
            . 'To: <' . $to . '>' . "\r\n"
            . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
            . 'MIME-Version: 1.0' . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n";
        // Dot-stuffing: a line starting with '.' must be escaped.
        $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $body));
        $ok = $code($cmd($headers . $safeBody . "\r\n.")) === 250;
        $cmd('QUIT');
        fclose($fp);
        return $ok;
    }

    /** RFC 2047 encode a header value if it contains non-ASCII. */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
