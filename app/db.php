<?php

declare(strict_types=1);

function get_db(): PDO
{
    $host = (string) (getenv('DB_HOST') ?: 'db');
    $name = (string) getenv('DB_NAME');
    $user = (string) getenv('DB_USER');
    $pass = (string) getenv('DB_PASSWORD');
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function get_items(): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, name, note FROM items ORDER BY id');
    $stmt->execute();
    return $stmt->fetchAll();
}

function add_item(string $name, string $note): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'INSERT INTO items (name, note) VALUES (:name, :note)'
    );
    $stmt->execute([':name' => $name, ':note' => $note]);
}
