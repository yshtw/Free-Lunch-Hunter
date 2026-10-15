-- Canonical schema for new installations. Existing installations: see docs/architecture.md.
-- All identifiers are lowercase; Linux does not need lower_case_table_names overrides.
CREATE TABLE IF NOT EXISTS user_account (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    username VARCHAR(80) NOT NULL UNIQUE,
    birth_date DATE NOT NULL,
    password VARCHAR(255) NOT NULL,
    reg_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS premium (
    user_id INT UNSIGNED PRIMARY KEY,
    premium TINYINT NOT NULL DEFAULT 0 CHECK (premium IN (0,1,2)),
    money DECIMAL(12,2) NOT NULL DEFAULT 0 CHECK (money >= 0),
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS activities (
    activity_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    img TEXT NULL,
    act_date DATE NOT NULL,
    place VARCHAR(200) NOT NULL,
    startt TIME NOT NULL,
    endt TIME NOT NULL,
    url TEXT NULL,
    lunch VARCHAR(200) NULL,
    KEY activity_schedule (act_date,startt,activity_id),
    KEY activity_owner (user_id),
    CHECK (endt > startt),
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS join_activity (
    user_id INT UNSIGNED NOT NULL,
    activity_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id,activity_id),
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES activities(activity_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS articles (
    article_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    img TEXT NULL,
    art_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rate INT NOT NULL DEFAULT 0,
    KEY article_feed (art_date,article_id),
    KEY article_owner (user_id),
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS comments (
    comment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    comm_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY comment_thread (article_id,comm_date),
    FOREIGN KEY (article_id) REFERENCES articles(article_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS votes (
    user_id INT UNSIGNED NOT NULL,
    article_id INT UNSIGNED NOT NULL,
    rate TINYINT NOT NULL CHECK (rate IN (-1,1)),
    PRIMARY KEY (user_id,article_id),
    FOREIGN KEY (article_id) REFERENCES articles(article_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wallet_entries (
    entry_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    action VARCHAR(40) NOT NULL,
    reference VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY wallet_event (user_id,action,reference),
    KEY wallet_history (user_id,entry_id),
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS membership_orders (
    order_id CHAR(32) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    product_code VARCHAR(40) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status='completed'),
    idempotency_key VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY order_request (user_id,idempotency_key),
    KEY order_history (user_id,created_at),
    FOREIGN KEY (user_id) REFERENCES user_account(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
