# Beat

A small self-hosted time tracker. Start timers on your projects from any device and check where your hours went.

- Start and stop timers, and run several at once
- Log grouped by day with daily totals; edit, add or delete entries
- Projects with colours, archiving and renaming
- Reports by week, month, year or custom range, with hours per project and per day
- CSV export in your local time zone
- Works on phones and can be added to the home screen
- Light and dark mode

Built with plain PHP, PostgreSQL and vanilla JavaScript. No framework and no build step.

## Requirements

- PHP 8.1+ with `pdo_pgsql`
- PostgreSQL
- A web server that runs PHP (Apache, nginx + PHP-FPM, …), served over HTTPS

## Setup

1. Put this folder in your web root so it's served at `/beat/`.
   To use another path, change the cookie `path` in `lib/bootstrap.php` and `start_url`/`scope` in `manifest.webmanifest`.

2. Create the database:

   ```sh
   sudo -u postgres psql -c "CREATE ROLE beat_user LOGIN PASSWORD 'choose-a-password';"
   sudo -u postgres psql -c "CREATE DATABASE beat OWNER beat_user;"
   psql -h 127.0.0.1 -U beat_user -d beat -f schema.sql
   ```

3. Copy `lib/config.example.php` to `lib/config.php` and fill in the credentials.

4. Create your user. Leave out the password to have one generated:

   ```sh
   php lib/manage-user.php yourname
   ```

   Run the same command again to reset a forgotten password.

Then open `https://your-server/beat/` and sign in.

## Security notes

- Passwords are hashed with PHP's `password_hash`.
- Sessions are random tokens stored hashed in the database and sent as `HttpOnly`, `SameSite=Lax` cookies.
- Every request that changes data must include an `X-Beat` header, which blocks cross-site request forgery.
- Sign-in locks for 15 minutes after 8 failed attempts from the same IP.
- Files in `lib/` return 404 when requested directly.
