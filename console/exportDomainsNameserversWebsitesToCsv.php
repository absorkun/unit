<?php

require_once __DIR__ . "/../config/db.php";

$start = microtime(true);
$chunkSize = isset($argv[1]) ? (int) $argv[1] : 2000;
$safeChunkSize = max(1, min($chunkSize, 10000));
$rowLimit = isset($argv[2]) ? max(1, (int) $argv[2]) : null;

$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$dir = __DIR__ . "/../outputs";

if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$fileName = $dir . '/domains-nameservers-websites-' . date_format($now, 'H-i-s') . '.csv';
$file = fopen($fileName, 'w');

if ($file === false) {
    throw new RuntimeException("Gagal membuka file CSV: {$fileName}");
}

fputcsv($file, ['No', 'Domain', 'Domain Status', 'Tanggal Kadaluarsa', 'Nameserver', 'A Record', 'Website Status'], escape: '');

$pdo = Db::getPdo();
$offset = 0;
$number = 1;

while (true) {
    if ($rowLimit !== null && $number > $rowLimit) {
        break;
    }

    $effectiveLimit = $safeChunkSize;
    if ($rowLimit !== null) {
        $remaining = $rowLimit - ($number - 1);
        if ($remaining <= 0) {
            break;
        }
        $effectiveLimit = min($safeChunkSize, $remaining);
    }

    $sql = <<<SQL
        SELECT CONCAT(d.name, d.zone) AS domain,
               d.status AS domain_status,
             d.expired_date AS expired_at,
               n.name AS nameserver,
               n.ip AS ip,
               w.status AS website_status
        FROM domains d
        LEFT JOIN nameservers n
            ON n.domain_id = d.id
        LEFT JOIN websites w
            ON w.domain_id = d.id
        WHERE d.status = 'active'
            AND d.zone IN ('.go.id', '.desa.id')
        ORDER BY d.created_at DESC
        LIMIT :limit OFFSET :offset
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $effectiveLimit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        break;
    }

    foreach ($rows as $row) {
        fputcsv($file, [
            $number,
            $row['domain'],
            $row['domain_status'],
            $row['expired_at'],
            $row['nameserver'],
            $row['ip'],
            $row['website_status'],
        ], escape: '');

        $number++;
    }

    $offset += count($rows);

    if (count($rows) < $safeChunkSize) {
        break;
    }
}

fclose($file);

$total = $number - 1;
$duration = round(microtime(true) - $start, 2);

echo "Selesai export {$total} data ke {$fileName} dalam {$duration}s" . PHP_EOL;
