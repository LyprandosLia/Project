CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    note TEXT
);

INSERT INTO items (name, note) VALUES
    ('First item', 'This row came from db-init/init.sql'),
    ('Second item', 'Edit init.sql to change what gets seeded on first run'),
    ('Third item', 'This file only runs once, when the db volume is first created');
