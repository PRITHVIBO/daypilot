# DayPilot — Cross-device AI Work Assistant

DayPilot is an offline-first PWA for tasks, planning, calendar, reminders, analytics, notes and an AI work assistant. The production architecture is intentionally compatible with standard PHP + MySQL hosting so it can be deployed to a current Hostinger web-hosting plan; optional Docker is included for local development.

## Product capabilities
- Installable PWA on mobile and desktop.
- Offline task capture/edit/complete with IndexedDB and sync queue.
- Tasks with priorities, deadlines, effort and scheduled blocks.
- Built-in calendar for events and scheduled task blocks; ICS export.
- Daily planning and workload estimation.
- Work analytics: completion rate, overdue work, focus minutes, priority mix.
- Notes workspace with AI-generated study/work notes.
- Gemini 3.8 Flash agent with tool calling for task and planning operations.
- Browser push notification subscriptions with VAPID and a PHP cron worker for reminders.
- CSRF protection, password hashing, prepared SQL, server-side session auth.
- GitHub Actions for PHP/JS checks and Hostinger FTPS deployment.

## Local Docker development
1. Install Docker Desktop.
2. Copy `.env.example` to `.env` and set `GEMINI_API_KEY` if you want AI.
3. Run `docker compose up --build`.
4. Open `http://localhost:8081`.

The MySQL container is initialized from `database.sql` automatically on first boot.

## Hostinger deployment (current PHP + MySQL style hosting)
1. Create a MySQL database/user in Hostinger and import `database.sql` using phpMyAdmin.
2. Create `config.local.php` beside `config.php` and put your production DB credentials, Gemini key, and VAPID keys there. It is ignored by Git and protected by `.htaccess`.
3. Upload the project so `index.php`, `api.php`, `sw.js`, `manifest.webmanifest`, `assets/`, `vendor/`, `config.php`, and `.htaccess` are in the domain's public web root.
4. Ensure PHP 8.2+ and extensions `pdo_mysql`, `curl`, `openssl`, `mbstring`, and Composer dependencies are available. If Composer cannot run on the host, run `composer install --no-dev` in GitHub Actions and deploy the resulting `vendor/` directory.
5. Enable HTTPS. Service workers and push need a secure context (localhost is the development exception).
6. Add a Hostinger Cron Job to run `php /home/ACCOUNT/domains/YOUR_DOMAIN/public_html/cron/reminders.php` every 5 minutes.
7. In DayPilot, enable notifications from Settings.

## GitHub Actions
Set repository secrets:
- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_SERVER_DIR`
- `GEMINI_API_KEY` (used to create deployment-time config if you choose to extend the workflow)

The included `deploy-hostinger.yml` uses `SamKirkland/FTP-Deploy-Action@v4.4.0` with FTPS and excludes local secrets.

For real production secrets, prefer repository/environment secrets. Never commit `config.php`, `.env`, VAPID private keys, database passwords or AI keys.

## Google Calendar
DayPilot's built-in calendar and ICS export work without Google credentials. Google Calendar OAuth is intentionally kept as an optional integration layer so the core app remains deployable on shared PHP hosting. To add it, create an OAuth Web Application in Google Cloud, enable the Calendar API, and request only the scopes the app needs.

## VAPID keys for push
Generate VAPID keys once after Composer install:

```bash
php -r "require 'vendor/autoload.php'; print_r(Minishlink\\WebPush\\VAPID::createVapidKeys());"
```

Save the public and private keys in `config.local.php`. Do not regenerate them on every request.

## AI architecture
The browser never calls Gemini directly. It calls `api.php?action=ai_chat`; the PHP server injects the user's task/calendar context, exposes typed tools, executes approved tool calls locally, and sends results back to Gemini for the final response.

The current default model is `gemini-3.8-flash`, which is GA and supports function calling. See Google's Gemini API documentation for current model availability and tool-calling behavior.
