<?php
/**
 * Lightweight, zero-dependency SMTP Mailer for PHP
 * Supports SSL (Port 465) and TLS / STARTTLS (Port 587)
 */
class SimpleSMTP {
    public static function send($to, $subject, $htmlBody, $settings = []) {
        $host = trim($settings['smtp_host'] ?? '');
        $port = intval($settings['smtp_port'] ?? 587);
        $user = trim($settings['smtp_user'] ?? '');
        $pass = trim($settings['smtp_pass'] ?? '');
        $fromEmail = trim($settings['smtp_from_email'] ?? $user);
        $fromName  = trim($settings['smtp_from_name'] ?? 'ARAL Reading Assessment');

        if (empty($host) || empty($user) || empty($pass)) {
            return ['success' => false, 'error' => 'SMTP is not fully configured in settings.'];
        }

        $timeout = 15;
        $isSSL = ($port === 465);
        $protocol = $isSSL ? 'ssl://' : '';

        $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return ['success' => false, 'error' => "Cannot connect to SMTP server ($host:$port): $errstr ($errno)"];
        }

        stream_set_timeout($socket, $timeout);

        $read = function() use ($socket) {
            $data = '';
            while ($str = fgets($socket, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') {
                    break;
                }
            }
            return $data;
        };

        $write = function($cmd) use ($socket) {
            fputs($socket, $cmd . "\r\n");
        };

        $resp = $read();
        if (substr($resp, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP greeting failed: $resp"];
        }

        $clientHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $write("EHLO $clientHost");
        $resp = $read();

        // If port 587, initiate STARTTLS
        if (!$isSSL && ($port === 587 || strpos($resp, 'STARTTLS') !== false)) {
            $write("STARTTLS");
            $resp = $read();
            if (substr($resp, 0, 3) !== '220') {
                fclose($socket);
                return ['success' => false, 'error' => "STARTTLS failed: $resp"];
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                return ['success' => false, 'error' => 'TLS encryption handshake failed.'];
            }

            $write("EHLO $clientHost");
            $resp = $read();
        }

        // AUTH LOGIN
        $write("AUTH LOGIN");
        $resp = $read();
        if (substr($resp, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'error' => "AUTH LOGIN rejected: $resp"];
        }

        $write(base64_encode($user));
        $resp = $read();
        if (substr($resp, 0, 3) !== '334') {
            fclose($socket);
            return ['success' => false, 'error' => "Username rejected: $resp"];
        }

        $write(base64_encode($pass));
        $resp = $read();
        if (substr($resp, 0, 3) !== '235') {
            fclose($socket);
            return ['success' => false, 'error' => "Authentication failed (Invalid SMTP Password/API Key): $resp"];
        }

        // MAIL FROM
        $write("MAIL FROM: <$fromEmail>");
        $resp = $read();
        if (substr($resp, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'error' => "MAIL FROM rejected: $resp"];
        }

        // RCPT TO
        $write("RCPT TO: <$to>");
        $resp = $read();
        if (substr($resp, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'error' => "Recipient rejected: $resp"];
        }

        // DATA
        $write("DATA");
        $resp = $read();
        if (substr($resp, 0, 3) !== '354') {
            fclose($socket);
            return ['success' => false, 'error' => "DATA command rejected: $resp"];
        }

        $boundary = md5(uniqid(time()));
        $headers  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";

        $encodedBody = chunk_split(base64_encode($htmlBody));

        $msg = $headers . "\r\n" . $encodedBody . "\r\n.";
        $write($msg);
        $resp = $read();
        if (substr($resp, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'error' => "Message rejected: $resp"];
        }

        $write("QUIT");
        fclose($socket);

        return ['success' => true];
    }
}
