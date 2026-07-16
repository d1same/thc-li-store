<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$result = \App\EmailService::processQueue(25);
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
