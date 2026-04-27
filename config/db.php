<?php

class Db
{
    private static ?PDO $pdo = null;

    public static function getPdo()
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = getenv('DB_HOST') ?: "localhost";
        $user = getenv('DB_USER') ?: "root";
        $pass = getenv('DB_PASS') ?: "";
        $dbname = getenv('DB_NAME') ?: "dom_april";
        $charset = "utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            self::$pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=$charset", $user, $pass, $options);
            return self::$pdo;
        } catch (PDOException $e) {
            die("Error database connection: {$e->getMessage()}");
        }
    }
}