<?php

require_once __DIR__ . "/../website/service.php";

$web = new WebsiteService();
$start = microtime(true);
$chunkSize = isset($argv[1]) ? (int) $argv[1] : 2000;

$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

$dir = __DIR__ . "/../outputs";

if (!is_dir($dir)) {
	mkdir($dir, 0777, true);
}

$fileName = $dir . '/websites-' . date_format($now, 'H-i-s') . '.csv';

$total = $web->exportToCsv($fileName, $chunkSize);
$duration = round(microtime(true) - $start, 2);

echo "Selesai export {$total} data ke {$fileName} dalam {$duration}s" . PHP_EOL;