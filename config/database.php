<?php
require_once __DIR__ . '/env.php';

class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'localhost';
            $name = getenv('DB_NAME') ?: 'cbt_mtsn';
            $user = getenv('DB_USER') ?: 'cbt_mtsn';
            $pass = getenv('DB_PASS') ?: 'cbt_mtsn';
            $port = getenv('DB_PORT') ?: '3306';

            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }
}

function db(): PDO {
    return Database::getInstance();
}
