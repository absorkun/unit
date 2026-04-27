<?php

require_once __DIR__ . "/../config/db.php";

class DomainService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::getPdo();
    }

    public function getAll(?int $limit = null)
    {
        $sql = <<<SQL
            SELECT * FROM domains
            WHERE status = 'active'
                AND zone IN ('.go.id','.desa.id')
                -- AND domain_name_server IS NOT NULL
                -- AND NOT JSON_CONTAINS(domain_name_server, '"ns-expired.domain.go.id"')
            ORDER BY created_at DESC
        SQL;

        if ($limit)
            $sql .= " LIMIT $limit";

        return $this->pdo->query($sql)->fetchAll();
    }

    public function getDomainNames(int $limit = 500): array
    {
        $safeLimit = max(1, min($limit, 5000));

        $untestedSql = <<<SQL
            SELECT d.id, d.name, d.zone, CONCAT(d.name, d.zone) AS domainName
            FROM domains d
            WHERE d.status = 'active'
                AND d.zone IN ('.go.id','.desa.id')
                -- AND d.domain_name_server IS NOT NULL
                -- AND NOT JSON_CONTAINS(d.domain_name_server, '"ns-expired.domain.go.id"')
                AND NOT EXISTS (
                    SELECT 1
                    FROM nameservers n
                    WHERE n.domain_id = d.id
                )
            ORDER BY d.created_at DESC
            LIMIT :limit
        SQL;

        $stmt = $this->pdo->prepare($untestedSql);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        if (count($rows) >= $safeLimit) {
            return $rows;
        }

        $remaining = $safeLimit - count($rows);

        $incompleteSql = <<<SQL
            SELECT d.id, d.name, d.zone, CONCAT(d.name, d.zone) AS domainName
            FROM domains d
            INNER JOIN nameservers n
                ON n.domain_id = d.id
            WHERE d.status = 'active'
                AND d.zone IN ('.go.id','.desa.id')
                -- AND d.domain_name_server IS NOT NULL
                -- AND NOT JSON_CONTAINS(d.domain_name_server, '"ns-expired.domain.go.id"')
                AND (n.name IS NULL OR TRIM(n.name) = '')
            ORDER BY n.test_at ASC
            LIMIT :limit
        SQL;

        $incompleteStmt = $this->pdo->prepare($incompleteSql);
        $incompleteStmt->bindValue(':limit', $remaining, PDO::PARAM_INT);
        $incompleteStmt->execute();

        return array_merge($rows, $incompleteStmt->fetchAll());
    }

    public function getAllBrief(int $limit = 100): array
    {
        $safeLimit = max(1, min($limit, 5000));

        $sql = <<<SQL
            SELECT id, name, zone, status, created_at
            FROM domains
            WHERE status = 'active'
                AND zone IN ('.go.id','.desa.id')
                -- AND domain_name_server IS NOT NULL
                -- AND NOT JSON_CONTAINS(domain_name_server, '"ns-expired.domain.go.id"')
            ORDER BY created_at DESC
            LIMIT :limit
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getOne(int $id)
    {
        $sql = <<<SQL
            SELECT * FROM domains
            WHERE id = :id
            LIMIT 1            
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function getAllExcludeIds(int $limit = 800): array
    {
        $safeLimit = max(1, min($limit, 5000));

        $untestedSql = <<<SQL
        SELECT d.id, d.name, d.zone
        FROM domains d
        WHERE d.status = 'active'
            AND d.zone IN ('.go.id', '.desa.id')
            -- AND d.domain_name_server IS NOT NULL
            -- AND NOT JSON_CONTAINS(d.domain_name_server, '"ns-expired.domain.go.id"')
            AND NOT EXISTS (
                SELECT 1
                FROM websites w
                WHERE w.domain_id = d.id
            )
        ORDER BY d.created_at DESC
        LIMIT :limit
    SQL;

        $stmt = $this->pdo->prepare($untestedSql);
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        if (count($rows) >= $safeLimit) {
            return $rows;
        }

        $remaining = $safeLimit - count($rows);

        $retrySql = <<<SQL
        SELECT d.id, d.name, d.zone
        FROM domains d
        INNER JOIN websites w
            ON w.domain_id = d.id
        WHERE d.status = 'active'
            AND d.zone IN ('.go.id', '.desa.id')
            -- AND d.domain_name_server IS NOT NULL
            -- AND NOT JSON_CONTAINS(d.domain_name_server, '"ns-expired.domain.go.id"')
            AND w.status = 0
        ORDER BY w.test_at ASC
        LIMIT :limit
    SQL;

        $retryStmt = $this->pdo->prepare($retrySql);
        $retryStmt->bindValue(':limit', $remaining, PDO::PARAM_INT);
        $retryStmt->execute();

        return array_merge($rows, $retryStmt->fetchAll());
    }
}