CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    note TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    display_name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_preferences (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    daily_calorie_goal INT UNSIGNED NULL,
    daily_protein_goal_g DECIMAL(8, 2) UNSIGNED NULL,
    max_prep_minutes SMALLINT UNSIGNED NULL,
    fasting_start TIME NULL,
    fasting_end TIME NULL,
    dietary_notes TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_preferences_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_allergies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    allergen VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_user_allergies_user_allergen UNIQUE (user_id, allergen),
    CONSTRAINT fk_user_allergies_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingredients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    canonical_unit VARCHAR(24) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(160) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    prep_minutes SMALLINT UNSIGNED NOT NULL,
    base_servings SMALLINT UNSIGNED NOT NULL,
    instructions TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recipe_ingredients (
    recipe_id BIGINT UNSIGNED NOT NULL,
    ingredient_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12, 3) UNSIGNED NOT NULL,
    unit VARCHAR(24) NOT NULL,
    notes VARCHAR(255) NULL,
    PRIMARY KEY (recipe_id, ingredient_id),
    CONSTRAINT fk_recipe_ingredients_recipe
        FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE CASCADE,
    CONSTRAINT fk_recipe_ingredients_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pantry_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ingredient_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12, 3) UNSIGNED NOT NULL,
    unit VARCHAR(24) NOT NULL,
    expires_on DATE NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pantry_user_ingredient (user_id, ingredient_id),
    CONSTRAINT fk_pantry_inventory_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_pantry_inventory_ingredient
        FOREIGN KEY (ingredient_id) REFERENCES ingredients (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meal_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status ENUM('draft', 'approved') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_meal_plans_user_dates (user_id, starts_on, ends_on),
    CONSTRAINT fk_meal_plans_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS planned_meals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meal_plan_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    meal_date DATE NOT NULL,
    meal_slot ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
    servings SMALLINT UNSIGNED NOT NULL,
    CONSTRAINT uq_planned_meals_plan_date_slot
        UNIQUE (meal_plan_id, meal_date, meal_slot),
    CONSTRAINT fk_planned_meals_plan
        FOREIGN KEY (meal_plan_id) REFERENCES meal_plans (id) ON DELETE CASCADE,
    CONSTRAINT fk_planned_meals_recipe
        FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ratings_user_created (user_id, created_at),
    CONSTRAINT uq_ratings_user_recipe UNIQUE (user_id, recipe_id),
    CONSTRAINT chk_ratings_range CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_ratings_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_recipe
        FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO items (name, note)
SELECT seed.name, seed.note
FROM (
    SELECT 'First item' AS name, 'Legacy demo row retained for app/index.php' AS note
    UNION ALL SELECT 'Second item', 'Legacy demo data'
    UNION ALL SELECT 'Third item', 'Legacy demo data'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM items);

INSERT INTO users (id, email, display_name) VALUES
    (1, 'demo@food-os.local', 'Food OS Demo')
ON DUPLICATE KEY UPDATE email = VALUES(email), display_name = VALUES(display_name);

INSERT INTO user_preferences (
    user_id, daily_calorie_goal, daily_protein_goal_g, max_prep_minutes, dietary_notes
) VALUES (
    1, 2000, 100, 45, 'Development profile; review before real use.'
)
ON DUPLICATE KEY UPDATE
    daily_calorie_goal = VALUES(daily_calorie_goal),
    daily_protein_goal_g = VALUES(daily_protein_goal_g),
    max_prep_minutes = VALUES(max_prep_minutes),
    dietary_notes = VALUES(dietary_notes);

INSERT INTO user_allergies (user_id, allergen) VALUES
    (1, 'peanuts')
ON DUPLICATE KEY UPDATE allergen = VALUES(allergen);

INSERT INTO ingredients (id, name, canonical_unit) VALUES
    (1, 'Red lentils', 'g'),
    (2, 'Onion', 'g'),
    (3, 'Carrot', 'g'),
    (4, 'Chickpeas', 'g'),
    (5, 'Spinach', 'g'),
    (6, 'Rice', 'g'),
    (7, 'Tomatoes', 'g'),
    (8, 'Pasta', 'g'),
    (9, 'Garlic', 'g'),
    (10, 'Olive oil', 'ml')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    canonical_unit = VALUES(canonical_unit);

INSERT INTO recipes (id, slug, name, description, prep_minutes, base_servings, instructions) VALUES
    (1, 'red-lentil-soup', 'Red Lentil Soup', 'A simple lentil and vegetable soup.', 35, 2, 'Simmer lentils with onion, carrot, garlic, and water until tender.'),
    (2, 'chickpea-spinach-rice', 'Chickpea Spinach Rice', 'A pantry-friendly chickpea bowl.', 30, 2, 'Cook rice. Warm chickpeas with spinach, garlic, and olive oil; serve over rice.'),
    (3, 'tomato-garlic-pasta', 'Tomato Garlic Pasta', 'Pasta with a quick tomato sauce.', 25, 2, 'Cook pasta. Simmer tomatoes and garlic in olive oil, then combine.')
ON DUPLICATE KEY UPDATE
    slug = VALUES(slug),
    name = VALUES(name),
    description = VALUES(description),
    prep_minutes = VALUES(prep_minutes),
    base_servings = VALUES(base_servings),
    instructions = VALUES(instructions);

INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit) VALUES
    (1, 1, 180, 'g'),
    (1, 2, 80, 'g'),
    (1, 3, 100, 'g'),
    (1, 9, 6, 'g'),
    (2, 4, 240, 'g'),
    (2, 5, 100, 'g'),
    (2, 6, 150, 'g'),
    (2, 9, 5, 'g'),
    (2, 10, 15, 'ml'),
    (3, 7, 300, 'g'),
    (3, 8, 180, 'g'),
    (3, 9, 6, 'g'),
    (3, 10, 15, 'ml')
ON DUPLICATE KEY UPDATE
    quantity = VALUES(quantity),
    unit = VALUES(unit);

INSERT INTO pantry_inventory (id, user_id, ingredient_id, quantity, unit) VALUES
    (1, 1, 1, 250, 'g'),
    (2, 1, 4, 150, 'g'),
    (3, 1, 9, 20, 'g')
ON DUPLICATE KEY UPDATE
    user_id = VALUES(user_id),
    ingredient_id = VALUES(ingredient_id),
    quantity = VALUES(quantity),
    unit = VALUES(unit);
