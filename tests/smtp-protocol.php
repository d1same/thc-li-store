<?php
declare(strict_types=1);

if (($argv[1] ?? '') === '--server') {
    $port = (int) ($argv[2] ?? 0);
    $output = (string) ($argv[3] ?? '');
    $ready = (string) ($argv[4] ?? '');
    $server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);
    if (!is_resource($server)) {
        file_put_contents($ready, 'error:' . $errorNumber . ':' . $errorMessage);
        exit(2);
    }
    file_put_contents($ready, 'ready');
    $client = stream_socket_accept($server, 20);
    if (!is_resource($client)) {
        exit(3);
    }
    fwrite($client, "220 test-smtp ESMTP\r\n");
    $stage = 'commands';
    $username = '';
    $password = '';
    $message = '';
    while (($line = fgets($client, 4096)) !== false) {
        $trimmed = rtrim($line, "\r\n");
        if ($stage === 'data') {
            if ($trimmed === '.') {
                fwrite($client, "250 queued\r\n");
                $stage = 'commands';
            } else {
                $message .= $line;
            }
            continue;
        }
        if ($stage === 'username') {
            $username = (string) base64_decode($trimmed, true);
            fwrite($client, "334 UGFzc3dvcmQ6\r\n");
            $stage = 'password';
        } elseif ($stage === 'password') {
            $password = (string) base64_decode($trimmed, true);
            fwrite($client, "235 authenticated\r\n");
            $stage = 'commands';
        } elseif (str_starts_with($trimmed, 'EHLO ')) {
            fwrite($client, "250-test-smtp\r\n250-AUTH LOGIN\r\n250 SIZE 1000000\r\n");
        } elseif ($trimmed === 'AUTH LOGIN') {
            fwrite($client, "334 VXNlcm5hbWU6\r\n");
            $stage = 'username';
        } elseif (str_starts_with($trimmed, 'MAIL FROM:')) {
            fwrite($client, "250 sender ok\r\n");
        } elseif (str_starts_with($trimmed, 'RCPT TO:')) {
            fwrite($client, "250 recipient ok\r\n");
        } elseif ($trimmed === 'DATA') {
            fwrite($client, "354 send data\r\n");
            $stage = 'data';
        } elseif ($trimmed === 'QUIT') {
            fwrite($client, "221 goodbye\r\n");
            break;
        } else {
            fwrite($client, "500 unexpected command\r\n");
        }
    }
    file_put_contents($output, json_encode(['username' => $username, 'password' => $password, 'message' => $message]));
    fclose($client);
    fclose($server);
    exit(0);
}

require __DIR__ . '/../app/SmtpClient.php';

use App\SmtpClient;

$probe = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if (!is_resource($probe)) {
    fwrite(STDERR, "Could not reserve an SMTP test port.\n");
    exit(2);
}
$address = stream_socket_get_name($probe, false);
$port = (int) substr(strrchr((string) $address, ':'), 1);
fclose($probe);

$output = tempnam(sys_get_temp_dir(), 'smtp-output-');
$ready = tempnam(sys_get_temp_dir(), 'smtp-ready-');
@unlink($output);
@unlink($ready);
$process = proc_open(
    [PHP_BINARY, __FILE__, '--server', (string) $port, $output, $ready],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes
);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not start the SMTP fixture.\n");
    exit(3);
}
for ($i = 0; $i < 40 && !is_file($ready); $i++) {
    usleep(50_000);
}
if (!is_file($ready) || trim((string) file_get_contents($ready)) !== 'ready') {
    proc_terminate($process);
    fwrite(STDERR, "SMTP fixture did not become ready.\n");
    exit(4);
}

$message = "From: THC LI <receipts@example.test>\r\nTo: <customer@example.test>\r\nSubject: SMTP protocol test\r\n\r\nFirst line\r\n.Line beginning with a dot";
SmtpClient::send([
    'host' => '127.0.0.1',
    'port' => $port,
    'encryption' => 'none',
    'username' => 'receipts@example.test',
    'password' => 'fixture-password',
    'timeout' => 3,
], 'receipts@example.test', 'customer@example.test', $message);

foreach ($pipes as $pipe) {
    fclose($pipe);
}
$exitCode = proc_close($process);
$captured = is_file($output) ? json_decode((string) file_get_contents($output), true) : null;
@unlink($output);
@unlink($ready);

$checks = [
    'fixture exited cleanly' => $exitCode === 0,
    'SMTP AUTH LOGIN credentials were transmitted' => ($captured['username'] ?? '') === 'receipts@example.test' && ($captured['password'] ?? '') === 'fixture-password',
    'SMTP message headers and body were transmitted' => str_contains((string) ($captured['message'] ?? ''), 'Subject: SMTP protocol test') && str_contains((string) ($captured['message'] ?? ''), 'First line'),
    'SMTP dot-stuffing protected body data' => str_contains((string) ($captured['message'] ?? ''), '..Line beginning with a dot'),
];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
}
if (in_array(false, $checks, true)) {
    exit(1);
}
echo "SMTP protocol suite passed.\n";
