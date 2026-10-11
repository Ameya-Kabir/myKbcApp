# KBC Party

Multiplayer, party-style quiz in the Kaun Banega Crorepati spirit. One host runs a room, players join
with a 6-digit PIN on their phones, and every screen follows the host. Vanilla PHP 8, JavaScript, MySQL.
No WebSockets: screens stay in sync with lightweight AJAX short-polling (`api/sync_state.php`).

## Deploy (shared LAMP hosting)
1. Import `database_v2.sql` (phpMyAdmin). **It drops and recreates every table except `users`.**
2. Copy `config/config.local.php.example` to `config/config.local.php` and enter the DB credentials.
3. Upload everything (Apache; `.htaccess` blocks `config/`, `includes/`, `*.sql`, `*.md`). Serve over HTTPS.
4. Register an account, then make it an admin: `UPDATE users SET is_admin = 1 WHERE username = 'you';`
5. Add questions in `/admin/` (or keep the samples). Put picture/audio files under `assets/media/`
   and reference them as `assets/media/file.jpg`, or use full http(s) URLs.

## How a game runs
- **Host** (`host.php`): create a room on the home page, share the PIN, press *Start game*, then
  *Close submissions* / *Next question* / *Show leaderboard* / *End game*. At the end a podium is shown;
  *Start new session* resets scores in the same room.
- **Players** (`play.php`): follow whatever the host does; answers are locked after one submission.

## Question types
| Type | What players see |
|------|------------------|
| A1 | four buttons |
| A2 | typed answer (optionally case-sensitive) |
| B | picture + options or typed answer |
| C | picture hidden behind 16 tiles; host reveals 25 / 50 / 100% |
| D | audio clip |
| E | picture + audio |
| F | memory game: items shown, host presses *Hide & Ask*, players type them back in order |

## Layout
`api/` JSON endpoints (`host_controls`, `player_join`, `sync_state`, `submit_answer`, `auth`, `admin_questions`) ·
`includes/` shared PHP · `config/` DB + room settings · `assets/js` (`sync`, `media`, `confetti`, `host`, `play`, `admin`, `auth`, `lobby`) ·
`admin/` question bank editor.

The earlier single-player version is preserved under the git tag `v1-singleplayer`.
