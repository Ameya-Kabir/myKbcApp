-- KBC Party - multiplayer schema (v2)
-- Drops the single-player tables (scores, game_settings, old questions) and creates the
-- host/player tables. `users` is kept as-is, so existing accounts survive.
-- Import via phpMyAdmin or: mysql -u USER -p DBNAME < database_v2.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS answers;
DROP TABLE IF EXISTS session_questions;
DROP TABLE IF EXISTS session_settings;
DROP TABLE IF EXISTS session_players;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS scores;
DROP TABLE IF EXISTS game_settings;
DROP TABLE IF EXISTS questions;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- users (unchanged)
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

-- ---------------------------------------------------------------- questions
-- Per-type conventions (options_json / correct_answer / media_url):
--   A1  MCQ        options_json {"A":..,"B":..,"C":..,"D":..}   correct_answer 'A'..'D'
--   A2  text       options_json NULL                            correct_answer = expected text
--   B   picture    media_url = image; answer is A1-style or A2-style (options_json present => MCQ)
--   C   reveal     media_url = image, revealed 0/25/50/100% via sessions.reveal_stage
--   D   music      media_url = audio file
--   E   pic+music  media_url = image, options_json {"audio": "<audio url>", ...MCQ options if any}
--   F   memory     options_json {"items": ["apple","pear",...]} shown in phase 1;
--                  correct_answer = what players must type back
-- case_sensitive = 1 enforces exact case (e.g. strict CamelCase) for text answers.
CREATE TABLE questions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    q_type         ENUM('A1','A2','B','C','D','E','F') NOT NULL DEFAULT 'A1',
    question_text  TEXT         NOT NULL,
    media_url      VARCHAR(500) NULL DEFAULT NULL,
    options_json   JSON         NULL DEFAULT NULL,
    correct_answer VARCHAR(255) NOT NULL,
    case_sensitive TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_questions_type (q_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- sessions (rooms)
-- reveal_stage meaning depends on the current question's type:
--   C: 0 = hidden, 1 = 25%, 2 = 50%, 3 = 100%
--   F: 0 = show items (phase 1), 1 = hidden, players type the answer (phase 2)
-- state_version increments on every host action so polling clients can skip unchanged state.
-- A question has three phases: open (accepting answers) -> closed (timer ended, everyone answered or the
-- host closed it; answer still hidden) -> revealed (answer_revealed = 1, set only by the host).
CREATE TABLE sessions (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    host_id             INT UNSIGNED NOT NULL,
    room_pin            CHAR(6)      NOT NULL,
    status              ENUM('lobby','active','finished') NOT NULL DEFAULT 'lobby',
    current_question_id INT UNSIGNED NULL DEFAULT NULL,
    question_open       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = accepting answers (host can close early)',
    answer_revealed     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = host has revealed the answer; players only learn it now',
    question_started_at DATETIME(3)  NULL DEFAULT NULL COMMENT 'DB-clock time the countdown started; NULL = not running (e.g. memory phase 1)',
    reveal_stage        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    state_version       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_pin (room_pin),
    KEY idx_sessions_host (host_id),
    CONSTRAINT fk_sessions_host FOREIGN KEY (host_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sessions_question FOREIGN KEY (current_question_id) REFERENCES questions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_players (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    score      INT          NOT NULL DEFAULT 0,
    joined_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_player_session_user (session_id, user_id),
    KEY idx_player_user (user_id),
    CONSTRAINT fk_players_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_settings (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    points_correct INT NOT NULL DEFAULT 100,
    points_wrong   INT NOT NULL DEFAULT 0 COMMENT 'points deducted for a wrong answer',
    timer_seconds  SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'seconds players get per question',
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_session (session_id),
    CONSTRAINT fk_settings_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- answers (new, not in the brief)
-- One row per player per question per room: stops double-submits, powers "players_answered",
-- and keeps an audit trail for scoring.
CREATE TABLE answers (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    question_id    INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,
    answer_text    VARCHAR(255) NOT NULL,
    is_correct     TINYINT(1)   NOT NULL,
    points_awarded INT          NOT NULL DEFAULT 0,
    answered_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_answer_once (session_id, question_id, user_id),
    CONSTRAINT fk_answers_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- session_questions
-- Which questions a room has already asked, so "random next" never repeats one,
-- even if nobody answered it.
CREATE TABLE session_questions (
    session_id  INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    asked_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id, question_id),
    CONSTRAINT fk_sq_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_sq_question FOREIGN KEY (question_id) REFERENCES questions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- sample questions
INSERT INTO questions (q_type, question_text, media_url, options_json, correct_answer, case_sensitive) VALUES
('A1', 'What is the capital of India?', NULL,
    '{"A":"Mumbai","B":"New Delhi","C":"Kolkata","D":"Chennai"}', 'B', 0),
('A1', 'Which planet is known as the Red Planet?', NULL,
    '{"A":"Venus","B":"Jupiter","C":"Mars","D":"Saturn"}', 'C', 0),
('A2', 'What is the chemical symbol for gold?', NULL, NULL, 'Au', 1),
('A2', 'Which river is known as the Ganga of the South?', NULL, NULL, 'Godavari', 0),
('F', 'Memorise these items, then type them back in order, separated by commas.', NULL,
    '{"items":["apple","candle","river","piano"]}', 'apple,candle,river,piano', 0);

-- Media samples (files ship in assets/media/): B picture MCQ, D music typed, E picture + music MCQ
INSERT INTO questions (q_type, question_text, media_url, options_json, correct_answer, case_sensitive) VALUES
('B', 'Which planet is shown in the picture?', 'assets/media/saturn.svg',
    '{"A":"Jupiter","B":"Saturn","C":"Uranus","D":"Neptune"}', 'B', 0),
('D', 'Listen carefully. How many notes do you hear?', 'assets/media/four-notes.wav', NULL, '4', 0),
('E', 'Look at the picture and listen. How many beeps do you hear?', 'assets/media/saturn.svg',
    '{"A":"1","B":"2","C":"3","D":"4","audio":"assets/media/three-beeps.wav"}', 'C', 0);

-- C progressive reveal (typed) and a second F memory round
INSERT INTO questions (q_type, question_text, media_url, options_json, correct_answer, case_sensitive) VALUES
('C', 'Name the planet as the picture is revealed.', 'assets/media/saturn.svg', NULL, 'Saturn', 0),
('F', 'Memorise the colours in order, then type them back separated by commas.', NULL,
    '{"items":["red","blue","green","yellow","purple"]}', 'red,blue,green,yellow,purple', 0);

-- Promote an account to admin:
--   UPDATE users SET is_admin = 1 WHERE username = 'your_username';
