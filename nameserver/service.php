<?php

require_once __DIR__ . "/../config/db.php";

class NameserverService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::getPdo();
    }

    public function getAll(?int $limit = null, int $offset = 0): array
    {
        $sql = <<<SQL
            SELECT CONCAT(d.name, d.zone) AS domain, n.name, n.ip, n.test_at
            FROM nameservers n
            LEFT JOIN domains d
                ON d.id = n.domain_id
            ORDER BY n.test_at ASC
        SQL;

        if ($limit === null) {
            return $this->pdo->query($sql)->fetchAll();
        }

        $safeLimit = max(1, min($limit, 10000));
        $safeOffset = max(0, $offset);

        $sql .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function exportToCsv(string $fileName, int $chunkSize = 2000): int
    {
        $safeChunkSize = max(1, min($chunkSize, 10000));
        $file = fopen($fileName, 'w');

        if ($file === false) {
            throw new RuntimeException("Gagal membuka file CSV: {$fileName}");
        }

        fputcsv($file, ["No", "Domain", "Nameserver", "IP", "Waktu Pengujian"], escape: "");

        $offset = 0;
        $number = 1;

        while (true) {
            $rows = $this->getAll($safeChunkSize, $offset);
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                fputcsv($file, [
                    $number,
                    $row['domain'],
                    $row['name'],
                    $row['ip'],
                    $row['test_at'],
                ], escape: "");

                $number++;
            }

            $offset += count($rows);

            if (count($rows) < $safeChunkSize) {
                break;
            }
        }

        fclose($file);

        return $number - 1;
    }

    public function ensureTableReady(): void
    {
        $createTableSql = <<<SQL
            CREATE TABLE IF NOT EXISTS nameservers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                domain_id BIGINT UNSIGNED,
                name TEXT DEFAULT NULL,
                ip TEXT DEFAULT NULL,
                test_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (domain_id) REFERENCES domains(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $this->pdo->exec($createTableSql);

        $ipColumnExistsSql = <<<SQL
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
                AND table_name = 'nameservers'
                AND column_name = 'ip'
            LIMIT 1
        SQL;

        $ipExists = (bool) $this->pdo->query($ipColumnExistsSql)->fetchColumn();
        if (!$ipExists) {
            $this->pdo->exec("ALTER TABLE nameservers ADD COLUMN ip TEXT DEFAULT NULL AFTER name");
        }

        $indexExistsSql = <<<SQL
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
                AND table_name = 'nameservers'
                AND index_name = 'uq_idx_domain_id'
            LIMIT 1
        SQL;

        $exists = (bool) $this->pdo->query($indexExistsSql)->fetchColumn();
        if (!$exists) {
            $this->pdo->exec("CREATE UNIQUE INDEX uq_idx_domain_id ON nameservers(domain_id)");
        }
    }

    public function createOne(int $domain_id, ?string $name, ?string $ip = null)
    {
        $sql = <<<SQL
            INSERT INTO nameservers (domain_id, name, ip)
            VALUES (:domain_id, :name, :ip)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                ip = VALUES(ip)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'domain_id' => $domain_id,
            'name' => $name,
            'ip' => $ip,
        ]);
    }

    public function createMany(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $sql = <<<SQL
            INSERT INTO nameservers (domain_id, name, ip)
            VALUES (:domain_id, :name, :ip)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                ip = VALUES(ip)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $this->pdo->beginTransaction();

        try {
            $count = 0;
            foreach ($rows as $row) {
                $stmt->execute([
                    'domain_id' => (int) $row['domain_id'],
                    'name' => $row['name'] ?? null,
                    'ip' => $row['ip'] ?? null,
                ]);
                $count++;
            }

            $this->pdo->commit();
            return $count;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}