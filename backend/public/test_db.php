<?php
$start = microtime(true);
$host = 'db';
$db   = 'leave_db';
$user = 'leave_user';
$pass = 'secret';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     $end = microtime(true);
     echo "Connection success! Time: " . ($end - $start) . " seconds\n";
} catch (\PDOException $e) {
     $end = microtime(true);
     echo "Connection failed! Time: " . ($end - $start) . " seconds\n";
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
