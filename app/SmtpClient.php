<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class SmtpClient
{
    /** @param array{host:string,port:int,encryption:string,username:string,password:string,timeout:int} $config */
    public static function test(array $config): void
    {
        $stream = self::connect($config);
        try {
            self::authenticate($stream, $config);
            self::command($stream, 'QUIT', [221]);
        } finally {
            fclose($stream);
        }
    }

    /** @param array{host:string,port:int,encryption:string,username:string,password:string,timeout:int} $config */
    public static function send(array $config, string $from, string $to, string $message): void
    {
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP requires valid sender and recipient addresses.');
        }
        $stream = self::connect($config);
        try {
            self::authenticate($stream, $config);
            self::command($stream, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($stream, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($stream, 'DATA', [354]);
            $normalized = preg_replace("/\r\n|\r|\n/", "\r\n", $message) ?? $message;
            $normalized = preg_replace('/(?m)^\./', '..', $normalized) ?? $normalized;
            self::write($stream, rtrim($normalized, "\r\n") . "\r\n.\r\n");
            self::expect($stream, [250], 'message body');
            self::command($stream, 'QUIT', [221]);
        } finally {
            fclose($stream);
        }
    }

    /** @param array{host:string,port:int,encryption:string,username:string,password:string,timeout:int} $config
     *  @return resource
     */
    private static function connect(array $config)
    {
        self::validateConfig($config);
        $secure = $config['encryption'] === 'ssl';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $config['host'],
                'SNI_enabled' => true,
            ],
        ]);
        $target = ($secure ? 'ssl' : 'tcp') . '://' . $config['host'] . ':' . $config['port'];
        $stream = @stream_socket_client(
            $target,
            $errorNumber,
            $errorMessage,
            $config['timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new RuntimeException('SMTP connection failed: ' . self::safeError($errorMessage, $errorNumber));
        }
        stream_set_timeout($stream, $config['timeout']);
        self::expect($stream, [220], 'server greeting');
        self::command($stream, 'EHLO ' . self::clientName(), [250]);
        if ($config['encryption'] === 'tls') {
            self::command($stream, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            self::command($stream, 'EHLO ' . self::clientName(), [250]);
        }
        return $stream;
    }

    /** @param resource $stream
     *  @param array{username:string,password:string} $config
     */
    private static function authenticate($stream, array $config): void
    {
        self::command($stream, 'AUTH LOGIN', [334]);
        self::command($stream, base64_encode($config['username']), [334], 'SMTP username');
        self::command($stream, base64_encode($config['password']), [235], 'SMTP password');
    }

    /** @param array{host:string,port:int,encryption:string,username:string,password:string,timeout:int} $config */
    private static function validateConfig(array $config): void
    {
        if (!preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)$/', $config['host'])) {
            throw new RuntimeException('Enter a valid SMTP hostname without http:// or https://.');
        }
        if ($config['port'] < 1 || $config['port'] > 65535) {
            throw new RuntimeException('Enter a valid SMTP port.');
        }
        if (!in_array($config['encryption'], ['ssl', 'tls', 'none'], true)) {
            throw new RuntimeException('Choose SSL, STARTTLS, or no SMTP encryption.');
        }
        if ($config['username'] === '' || $config['password'] === '') {
            throw new RuntimeException('SMTP username and password are required.');
        }
    }

    /** @param resource $stream */
    private static function command($stream, string $command, array $accepted, ?string $step = null): string
    {
        self::write($stream, $command . "\r\n");
        return self::expect($stream, $accepted, $step ?? (preg_replace('/\s+.*/', '', $command) ?: 'command'));
    }

    /** @param resource $stream */
    private static function write($stream, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($stream, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('SMTP connection closed while sending data.');
            }
            $written += $result;
        }
    }

    /** @param resource $stream */
    private static function expect($stream, array $accepted, string $step): string
    {
        $response = '';
        do {
            $line = fgets($stream, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($stream);
                throw new RuntimeException(($meta['timed_out'] ?? false)
                    ? 'SMTP timed out during ' . $step . '.'
                    : 'SMTP connection closed during ' . $step . '.');
            }
            $response .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $accepted, true)) {
            $detail = trim(preg_replace('/^\d{3}[ -]?/m', '', $response) ?? '');
            throw new RuntimeException('SMTP rejected ' . $step . ' (' . $code . '): ' . mb_substr($detail, 0, 180));
        }
        return $response;
    }

    private static function clientName(): string
    {
        $host = parse_url((string) getenv('APP_URL'), PHP_URL_HOST);
        return is_string($host) && preg_match('/^[A-Za-z0-9.-]+$/', $host) ? $host : 'localhost';
    }

    private static function safeError(string $message, int $number): string
    {
        $message = trim(preg_replace('/[\r\n]+/', ' ', $message) ?? '');
        return $message !== '' ? mb_substr($message, 0, 180) : 'network error ' . $number;
    }
}
