# HELOS AWS Deployment

Production domain: `finance.coeditorlanka.com`

## Recommended small setup

- Compute: one small Ubuntu server, either AWS Lightsail 2 GB or EC2 `t4g.small`.
- Database: PostgreSQL. Use local PostgreSQL on the same server for the first low-traffic launch, then move to RDS when the app becomes important enough to justify managed backups and isolation.
- Web server: Nginx + PHP-FPM 8.2 or 8.3.
- Background work: Laravel queue worker using the `database` queue.
- SSL: Let's Encrypt certificate for `finance.coeditorlanka.com`.

## DNS

Create this DNS record wherever `coeditorlanka.com` is managed:

```text
Type: A
Name: finance
Value: <server public IPv4>
TTL: 300
```

If the server uses IPv6, add an `AAAA` record as well.

## Server packages

Install:

- Nginx
- PHP 8.2 or 8.3 FPM
- PHP extensions: `bcmath`, `cli`, `curl`, `mbstring`, `pgsql`, `xml`, `zip`, `intl`
- Composer
- Node.js 22 LTS or current compatible Node
- PostgreSQL
- Certbot with the Nginx plugin

## Application setup

```bash
cd /var/www
git clone <production-repo-url> helosfinance
cd helosfinance
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.production.example .env
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Set the real values in `.env`, especially `APP_KEY`, database password, `APP_URL`, and integration secrets.

## Queue worker

Run one queue worker under Supervisor:

```bash
php /var/www/helosfinance/artisan queue:work database --sleep=3 --tries=3 --timeout=90
```

## Nginx site

Point the web root to:

```text
/var/www/helosfinance/public
```

The PHP socket should match the installed PHP-FPM version.

## SSL

After DNS points to the server:

```bash
certbot --nginx -d finance.coeditorlanka.com
```

## Data connection

Keep HELOS hosted independently. If order data needs to move from another app later, expose a neutral order API from that source app and let HELOS consume it through a controlled integration.
