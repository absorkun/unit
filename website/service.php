<?php

require_once __DIR__ . "/../config/db.php";

class WebsiteService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::getPdo();
    }

    public function getAll(?int $limit = null, int $offset = 0): array
    {
        $sql = <<<SQL
            SELECT CONCAT(d.name,d.zone) AS domain, w.status, w.test_at
            FROM websites w
            LEFT JOIN domains d
                ON d.id = w.domain_id
            ORDER BY w.test_at ASC
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

    public function toCsv(array $data, ?string $fileName = "websites.csv")
    {
        $file = fopen($fileName, 'w');
        if ($file === false) {
            throw new RuntimeException("Gagal membuka file CSV: {$fileName}");
        }

        $headers = ["No", "Domain", "Status", "Waktu Pengujian"];
        fputcsv($file, $headers, escape: "");
        foreach ($data as $i => $item) {
            fputcsv($file, [
                $i + 1,
                $item['domain'],
                $item['status'],
                $item['test_at'],
            ], escape: "");
        }

        fclose($file);
    }

    public function exportToCsv(string $fileName, int $chunkSize = 2000): int
    {
        $safeChunkSize = max(1, min($chunkSize, 10000));
        $file = fopen($fileName, 'w');

        if ($file === false) {
            throw new RuntimeException("Gagal membuka file CSV: {$fileName}");
        }

        fputcsv($file, ["No", "Domain", "Status", "Waktu Pengujian"], escape: "");

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
                    $row['status'],
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

    public function getActiveDomainIds()
    {
        $sql = <<<SQL
            SELECT domain_id FROM websites
            WHERE status = 1;
        SQL;

        $actives = $this->pdo->query($sql)->fetchAll();

        return array_column($actives, 'domain_id');
    }

    public function createMany(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $sql = <<<SQL
            INSERT INTO websites (domain_id, status)
            VALUES (:domain_id, :status)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $this->pdo->beginTransaction();

        try {
            $count = 0;
            foreach ($rows as $row) {
                $stmt->execute([
                    'domain_id' => (int) $row['domain_id'],
                    'status' => !empty($row['status']) ? 1 : 0,
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

    public function createOne(int $domain_id, bool $status)
    {
        $sql = <<<SQL
            INSERT INTO websites (domain_id, status)
            VALUES (:domain_id, :status)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'domain_id' => $domain_id,
            'status' => $status ? 1 : 0,
        ]);
    }
}