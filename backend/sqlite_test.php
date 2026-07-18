<?php
try {
    $pdo = new PDO('mysql:host=db;port=3306;dbname=eleave_test', 'root', '');
    $stmt = $pdo->query('SELECT 1');
    echo "MySQL OK: " . $stmt->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
