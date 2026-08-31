# FieldPulse

FieldPulse is a PHP/MySQL field-service management app for customers, service
requests, job cards, technicians, inventory, scheduling, reports, signatures,
notifications, and job photos.

## Run locally

1. Create a MySQL 8 database, or let `setup.php` create
   `field_service_db` when the local account has permission.
2. Copy `.env.example` to `.env` and set the database and administrator values.
3. Start Apache/PHP from the repository root:

   ```bash
   php -S 0.0.0.0:8080 -t .
   ```

4. Open `http://localhost:8080/setup.php` once, then sign in at
   `http://localhost:8080/login.php`.

The built-in demo dataset is disabled by default. Set `DEMO_MODE=true` and
`SEED_DEMO_DATA=true` only for a non-production demo environment.

## Deploy to Render

This repository is configured for Render's Docker runtime because PHP is not a
native Render runtime.

1. Create a **MySQL 8** database on Render (or use another reachable MySQL 8
   provider). Render's Blueprint file can create the web service, but the
   MySQL instance is configured separately.
2. In Render, create a Blueprint from this repository, or create a Docker Web
   Service with `Dockerfile` at the repository root.
3. Add the `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
   `ADMIN_EMAIL`, and `ADMIN_PASSWORD` values from the database/admin setup.
   Keep `DEMO_MODE=false` and `SEED_DEMO_DATA=false` in production.
4. After the first deploy, visit `/setup.php` once to create the tables and
   initial administrator. Then use `/login.php`.

The Render blueprint includes a 1 GB persistent disk mounted at
`assets/uploads`, which keeps technician photos across deploys. Persistent
disks require a paid service plan; if you remove the disk for a free demo,
uploaded photos are ephemeral.

Never commit `.env`, database passwords, or other credentials.