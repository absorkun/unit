<?php

require_once __DIR__ . "/../nameserver/service.php";
require_once __DIR__ . "/../domain/service.php";

const CURL_TIMEOUT_SECONDS = 10;
const DOMAIN_LIMIT_PER_RUN = 50;
const PARALLEL_LIMIT = 15;
const DB_BATCH_SIZE = 250;

$ns = new NameserverService();
$dom = new DomainService();

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

function parseRdapNameservers(string $payload): ?array
{
    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return null;
    }

    $ldhNames = [];
    $ips = [];

    if (!isset($data['nameservers']) || !is_array($data['nameservers'])) {
        return [
            'names' => [],
            'ips' => [],
        ];
    }

    foreach ($data['nameservers'] as $nameserver) {
        if (!isset($nameserver['ldhName'])) {
            continue;
        }

        $name = strtolower(trim((string) $nameserver['ldhName']));
        if ($name !== '') {
            $ldhNames[$name] = true;
        }

        if (
            isset($nameserver['ipAddresses'])
            && is_array($nameserver['ipAddresses'])
            && isset($nameserver['ipAddresses']['v4'])
            && is_array($nameserver['ipAddresses']['v4'])
        ) {
            foreach ($nameserver['ipAddresses']['v4'] as $ip) {
                $safeIp = trim((string) $ip);
                if (filter_var($safeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[$safeIp] = true;
                }
            }
        }
    }

    return [
        'names' => array_keys($ldhNames),
        'ips' => array_keys($ips),
    ];
}

function resolveARecords(array $nameservers): array
{
    if (empty($nameservers)) {
        return [];
    }

    $ips = [];
    foreach ($nameservers as $nameserver) {
        $records = @dns_get_record($nameserver, DNS_A);
        if (!is_array($records)) {
            continue;
        }

        foreach ($records as $record) {
            if (!isset($record['ip'])) {
                continue;
            }

            $ip = trim((string) $record['ip']);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[$ip] = true;
            }
        }
    }

    return array_keys($ips);
}

function fetchNameserversParallel(array $domains, int $parallelLimit, string $logFile): array
{
    if (empty($domains)) {
        return [];
    }

    $results = [];

    foreach (array_chunk($domains, $parallelLimit) as $chunk) {
        $mh = curl_multi_init();
        $meta = [];

        foreach ($chunk as $domain) {
            $domainName = $domain['domainName'];
            $url = "https://rdap.pandi.id/rdap/domain/{$domainName}";

            $ch = curl_init($url);
            if ($ch === false) {
                writeLog($logFile, "Gagal inisialisasi cURL: {$domainName}");
                $results[] = [
                    'domain_id' => (int) $domain['id'],
                    'name' => null,
                    'ip' => null,
                    'fetched' => false,
                    'has_nameserver' => false,
                ];
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => CURL_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => CURL_TIMEOUT_SECONDS,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $key = spl_object_id($ch);
            $meta[$key] = [
                'handle' => $ch,
                'domain_id' => (int) $domain['id'],
                'domain_name' => $domainName,
                'url' => $url,
            ];

            curl_multi_add_handle($mh, $ch);
        }

        if (empty($meta)) {
            curl_multi_close($mh);
            continue;
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
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $payload = curl_multi_getcontent($ch);

            $fetched = false;
            $nameserverNames = [];
            $ipAddresses = [];

            if ($errno !== 0) {
                writeLog($logFile, "HTTP error {$errno} on {$item['url']}: " . curl_error($ch));
            } elseif ($httpCode !== 200 || $payload === false || $payload === '') {
                writeLog($logFile, "HTTP code {$httpCode} on {$item['url']}");
            } else {
                $parsed = parseRdapNameservers((string) $payload);
                if ($parsed === null) {
                    writeLog($logFile, "Invalid JSON on {$item['url']}");
                } else {
                    $fetched = true;
                    $nameserverNames = $parsed['names'];
                    $ipAddresses = $parsed['ips'];

                    if (!empty($nameserverNames) && empty($ipAddresses)) {
                        $ipAddresses = resolveARecords($nameserverNames);
                    }
                }
            }

            $results[] = [
                'domain_id' => $item['domain_id'],
                'name' => $fetched ? implode(',', $nameserverNames) : null,
                'ip' => $fetched ? implode(',', $ipAddresses) : null,
                'fetched' => $fetched,
                'has_nameserver' => !empty($nameserverNames),
                'has_ip' => !empty($ipAddresses),
            ];

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);
    }

    return $results;
}

try {
    $start = microtime(true);
    $ns->ensureTableReady();

    $outputDir = __DIR__ . "/../outputs";
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $logFile = $outputDir . "/create-nameserver-error-" . date('Ymd-His') . ".log";

    $domainLimit = cliIntArg($argv, 1, DOMAIN_LIMIT_PER_RUN, 1, 5000);
    $parallelLimit = cliIntArg($argv, 2, PARALLEL_LIMIT, 1, 100);
    $dbBatchSize = cliIntArg($argv, 3, DB_BATCH_SIZE, 1, 2000);

    $domains = $dom->getDomainNames($domainLimit);

    echo "Jumlah domains yang diolah: " . count($domains) . PHP_EOL;
    echo "Konfigurasi run => limit: {$domainLimit}, paralel: {$parallelLimit}, batch DB: {$dbBatchSize}" . PHP_EOL;

    $checked = fetchNameserversParallel($domains, $parallelLimit, $logFile);

    $saved = 0;
    foreach (array_chunk($checked, $dbBatchSize) as $rows) {
        $saved += $ns->createMany($rows);
    }

    $fetchedCount = count(array_filter($checked, fn($row) => !empty($row['fetched'])));
    $failedCount = count($checked) - $fetchedCount;
    $hasNameserverCount = count(array_filter($checked, fn($row) => !empty($row['has_nameserver'])));
    $hasIpCount = count(array_filter($checked, fn($row) => !empty($row['has_ip'])));
    $duration = round(microtime(true) - $start, 2);

    echo "Selesai. Total: " . count($checked)
        . ", fetch sukses: " . $fetchedCount
        . ", fetch gagal: " . $failedCount
        . ", punya NS: " . $hasNameserverCount
        . ", punya IP: " . $hasIpCount
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

    $logFile = $outputDir . "/create-nameserver-error-" . date('Ymd-His') . ".log";
    writeLog($logFile, "Fatal error: {$e->getMessage()}");
    echo "Gagal. Cek log: {$logFile}" . PHP_EOL;
}