<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$day = max(1, min(28, (int) \App\Store::setting('marketing_campaign_day', 1)));
if ((int) date('j') !== $day) { echo "Not the configured draft day.\n"; exit; }
$id = \App\CampaignService::createMonthlyDraft();
echo $id ? "Created campaign draft {$id}. Owner approval is still required.\n" : "No draft created.\n";
