<?php

require_once __DIR__ . "/../domain/service.php";
require_once __DIR__ . "/../website/service.php";

const CURL_TIMEOUT_SECONDS = 10;
const DOMAIN_LIMIT_PER_RUN = 800;
const PARALLEL_LIMIT = 20;
const DB_BATCH_SIZE = 300;

function cliIntArg(array $argv, int $index, int $default, int $min, int $max): int
{
    if (!isset($argv[$index]) || !is_numeric($argv[$index])) {
        return $default;
    }

    return max($min, min((int) $argv[$index], $max));
}

function writeLog(string $logFile, string $message): void
{
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] $message" . PHP_EOL, FILE_APPEND);
}

function checkContentsParallel(array $domains, int $parallelLimit, string $logFile): array
{
    if (empty($domains)) {
        return [];
    }

    $results = [];

    foreach (array_chunk($domains, $parallelLimit) as $chunk) {
        $mh = curl_multi_init();
        $meta = [];
        $flags = [];

        foreach ($chunk as $d) {
            $url = "https://" . $d['name'] . $d['zone'];
            $ch = curl_init($url);
            $key = spl_object_id($ch);
            $flags[$key] = false;

            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => CURL_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => CURL_TIMEOUT_SECONDS,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$flags, $key) {
                    $flags[$key] = trim($data) !== '';
                    return -1;
                },
            ]);

            $meta[$key] = [
                'handle' => $ch,
                'domain_id' => (int) $d['id'],
                'url' => $url,
            ];

            curl_multi_add_handle($mh, $ch);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        foreach ($meta as $item) {
            $ch = $item['handle'];
            $errno = curl_errno($ch);

            if ($errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
                writeLog($logFile, "HTTP error {$errno} on {$item['url']}: " . curl_error($ch));
            }

            $results[] = [
                'domain_id' => $item['domain_id'],
                'status' => !empty($flags[spl_object_id($ch)]),
            ];

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);
    }

    return $results;
}

$dom = new DomainService();
$web = new WebsiteService();

try {
    $start = microtime(true);
    $outputDir = __DIR__ . "/../outputs";
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $logFile = $outputDir . "/create-website-error-" . date('Ymd-His') . ".log";

    $domainLimit = cliIntArg($argv, 1, DOMAIN_LIMIT_PER_RUN, 1, 5000);
    $parallelLimit = cliIntArg($argv, 2, PARALLEL_LIMIT, 1, 100);
    $dbBatchSize = cliIntArg($argv, 3, DB_BATCH_SIZE, 1, 2000);

    $excludes = $dom->getAllExcludeIds($domainLimit);

    echo "Jumlah domains yang diolah: " . count($excludes) . PHP_EOL;
    echo "Konfigurasi run => limit: {$domainLimit}, paralel: {$parallelLimit}, batch DB: {$dbBatchSize}" . PHP_EOL;

    $checked = checkContentsParallel($excludes, $parallelLimit, $logFile);

    $saved = 0;
    foreach (array_chunk($checked, $dbBatchSize) as $rows) {
        $saved += $web->createMany($rows);
    }

    $activeCount = count(array_filter($checked, fn($row) => !empty($row['status'])));
    $inactiveCount = count($checked) - $activeCount;
    $duration = round(microtime(true) - $start, 2);

    echo "Selesai. Total: " . count($checked)
        . ", aktif: " . $activeCount
        . ", tidak aktif: " . $inactiveCount
        . ", tersimpan: " . $saved
        . ", durasi: {$duration}s"
        . PHP_EOL;

    if (is_file($logFile) && filesize($logFile) > 0) {
        echo "Ada log error di: {$logFile}" . PHP_EOL;
    }
} catch (Throwable $e) {
    $outputDir = __DIR__ . "/../outputs";
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $logFile = $outputDir . "/create-website-error-" . date('Ymd-His') . ".log";
    writeLog($logFile, "Fatal error: {$e->getMessage()}");
    echo "Gagal. Cek log: {$logFile}" . PHP_EOL;
}