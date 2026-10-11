# KBC Quiz

Point-based Kaun Banega Crorepati style quiz. Vanilla PHP 8, JavaScript, MySQL.

## Deploy
1. Import `database.sql` (phpMyAdmin).
2. Copy `config/config.local.php.example` to `config/config.local.php` and enter DB credentials.
3. Upload everything (Apache; `.htaccess` blocks `config/`, `includes/` and `*.sql`). Serve over HTTPS.
4. Register an account, then promote it: `UPDATE users SET is_admin = 1 WHERE username = 'you';`
5. Replace the empty files in `assets/audio/` with real sounds (see `assets/audio/README.txt`).
