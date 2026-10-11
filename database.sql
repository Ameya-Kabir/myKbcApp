-- KBC Quiz - database schema
-- Import via phpMyAdmin or: mysql -u USER -p DBNAME < database.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)  NOT NULL,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_admin      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_text TEXT         NOT NULL,
    opt_a         VARCHAR(255) NOT NULL,
    opt_b         VARCHAR(255) NOT NULL,
    opt_c         VARCHAR(255) NOT NULL,
    opt_d         VARCHAR(255) NOT NULL,
    correct_opt   ENUM('A','B','C','D') NOT NULL,
    difficulty    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=easy, 2=medium, 3=hard',
    PRIMARY KEY (id),
    KEY idx_questions_difficulty (difficulty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_id NULL = global default. Note: MySQL UNIQUE allows multiple NULLs, so
-- uniqueness of global keys is enforced in application code (see config/settings.php).
CREATE TABLE IF NOT EXISTS game_settings (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NULL DEFAULT NULL,
    setting_key   VARCHAR(64)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_user_key (user_id, setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scores (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    total_points    INT          NOT NULL DEFAULT 0,
    correct_answers SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    wrong_answers   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    played_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scores_user (user_id),
    KEY idx_scores_points (total_points),
    CONSTRAINT fk_scores_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global default settings (user_id NULL)
INSERT INTO game_settings (user_id, setting_key, setting_value)
SELECT NULL, d.k, d.v FROM (
    SELECT 'points_correct' AS k, '100' AS v UNION ALL
    SELECT 'points_wrong',        '50'  UNION ALL
    SELECT 'timer_seconds',       '30'  UNION ALL
    SELECT 'num_questions',       '10'  UNION ALL
    SELECT 'lifeline_5050',       '1'   UNION ALL
    SELECT 'lifeline_poll',       '1'   UNION ALL
    SELECT 'lifeline_expert',     '1'   UNION ALL
    SELECT 'limit_5050',          '1'   UNION ALL
    SELECT 'limit_poll',          '1'   UNION ALL
    SELECT 'limit_expert',        '1'
) d
WHERE NOT EXISTS (SELECT 1 FROM game_settings WHERE user_id IS NULL);

-- Sample questions
INSERT INTO questions (question_text, opt_a, opt_b, opt_c, opt_d, correct_opt, difficulty) VALUES
('What is the capital of India?', 'Mumbai', 'New Delhi', 'Kolkata', 'Chennai', 'B', 1),
('Which planet is known as the Red Planet?', 'Venus', 'Jupiter', 'Mars', 'Saturn', 'C', 1),
('Who wrote the Indian national anthem "Jana Gana Mana"?', 'Bankim Chandra Chatterjee', 'Rabindranath Tagore', 'Sarojini Naidu', 'Mahatma Gandhi', 'B', 2),
('What is the chemical symbol for gold?', 'Ag', 'Gd', 'Au', 'Go', 'C', 2),
('In which year did India gain independence?', '1945', '1947', '1950', '1952', 'B', 1);

-- Admin accounts: register a normal user, then promote:
--   UPDATE users SET is_admin = 1 WHERE username = 'your_username';
