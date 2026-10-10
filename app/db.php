<?php
// All database access lives here - index.php never talks to PDO
// directly. That separation is what lets you swap the database layer
// later (different schema, different engine) without touching the
// page logic.

function get_db(): PDO {
    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASSWORD');
    $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function get_items(): array {
    $pdo = get_db();
    return $pdo->query("SELECT * FROM items ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

function add_item(string $name, string $note): void {
    $pdo = get_db();
    $stmt = $pdo->prepare("INSERT INTO items (name, note) VALUES (?, ?)");
    $stmt->execute([$name, $note]);
}
