# Деплой на VPS

Як розгорнути Spread на власному сервері (Ubuntu 24.04) і що налаштувати, щоб систему не страшно було залишити без нагляду. Готові файли конфігурації лежать у `deploy/`. Шляхи нижче розраховані на `/var/www/spread` і користувача `www-data`.

---

## 1. Пакети

```bash
sudo install -d /usr/share/postgresql-common/pgdg
sudo curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" | sudo tee /etc/apt/sources.list.d/pgdg.list
sudo apt update
sudo apt install nginx redis-server postgresql-18 postgresql-client-18 \
  php8.5-fpm php8.5-cli php8.5-pgsql php8.5-redis php8.5-bcmath php8.5-intl php8.5-mbstring php8.5-xml php8.5-zip php8.5-curl \
  composer certbot
```

- `pg_dump` і `pg_restore` мають бути тієї ж мажорної версії, що й сервер PostgreSQL, або новіші: інакше бекап упаде з «server version mismatch».
- PHP 8.5 може знадобитися з PPA `ondrej/php`, якщо його ще немає в стандартних репозиторіях.

## 2. База й користувач

```bash
sudo -u postgres createuser --createdb --pwprompt spread
sudo -u postgres createdb --owner=spread spread
```

Право `CREATEDB` потрібне перевірці бекапу: вона щотижня відновлює останній бекап у тимчасову базу `spread_verify_*` і потім видаляє її. На диску має бути вільно приблизно стільки ж місця, скільки займає база.

## 3. Код і `.env`

```bash
sudo -u www-data git clone git@github.com:MaXXX0001/Spread.git /var/www/spread
cd /var/www/spread
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
```

У `.env` заповнити:

| Змінна | Значення |
|---|---|
| `APP_ENV`, `APP_DEBUG` | `production`, `false` |
| `APP_URL`, `SPREAD_TRACKER_URL` | `https://<домен трекера>` |
| `SPREAD_FALLBACK_URL` | куди слати відвідувачів невідомих кампаній |
| `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` | `127.0.0.1`, `spread`, пароль, `spread` |
| `REDIS_HOST` | `127.0.0.1` |
| `LOG_STACK`, `LOG_DAILY_DAYS` | `daily,telegram`, `14` |
| `TELEGRAM_BOT_TOKEN` | токен бота з @BotFather |
| `BACKUP_S3_*` | доступ до S3-сумісного сховища (розділ 6) |
| `HEALTHCHECKS_SCHEDULER_URL`, `HEALTHCHECKS_BACKUP_URL` | адреси пінгів (розділ 7) |

Потім:

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan make:filament-user   # перший користувач адмінки
sudo -u www-data php artisan spread:config:rebuild
sudo -u www-data php artisan spread:geoip:update
sudo -u www-data php artisan spread:rates:update
```

## 4. Воркери, планувальник, nginx

```bash
sudo cp deploy/systemd/spread-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now spread-clicks spread-postbacks spread-horizon

sudo cp deploy/cron/spread /etc/cron.d/spread

sudo cp deploy/nginx/spread.conf /etc/nginx/sites-available/spread
sudo sed -i 's/tracker.example.com/<домен трекера>/g' /etc/nginx/sites-available/spread
sudo ln -s /etc/nginx/sites-available/spread /etc/nginx/sites-enabled/spread
sudo certbot certonly --webroot -w /var/www/letsencrypt -d <домен трекера>
sudo nginx -t && sudo systemctl reload nginx
```

- Воркери кліків і постбеків за `SIGTERM` дообробляють поточну пачку й виходять. Тому `systemctl restart` після деплою нічого не губить.
- У nginx `/p/` обмежено до 20 запитів/с з однієї IP зі сплеском 100; понад ліміт — `429`. Редірект `/c/` не обмежується.
- Після кожного деплою: `php artisan migrate --force`, потім `sudo systemctl restart spread-clicks spread-postbacks spread-horizon`.

## 5. Адмінка і 2FA

- Вхід вимагає код із застосунку-автентифікатора. При першому вході адмінка відкриє сторінку налаштування: відскануйте QR-код і збережіть коди відновлення в менеджері паролів.
- Якщо втрачено і телефон, і коди відновлення, обнуліть 2FA на сервері; при наступному вході вона налаштовується заново:

```bash
sudo -u www-data php artisan tinker --execute "App\Models\User::where('email', '<email>')->update(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null]);"
```

## 6. Бекапи

- `spread:backup` щоночі о 03:00 (Київ) знімає `pg_dump` з маніфестом кількості рядків. Тримає 14 останніх у `storage/app/backups` і в бакеті (`db/`).
- `spread:backup:verify` щонеділі о 04:00 відновлює останній бекап у тимчасову базу й звіряє з маніфестом.
- `spread:clicks:archive` 1-го числа о 04:30 виносить кліки без конверсій, старші за 6 місяців, у `storage/app/archive/clicks-YYYY-MM.csv.gz` і в бакет (`archive/`).
- **Сховище:** бакет у Backblaze B2 або Cloudflare R2 і ключ з доступом лише до нього. У `.env`: `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, `BACKUP_S3_REGION`, `BACKUP_S3_BUCKET`, `BACKUP_S3_ENDPOINT` (наприклад, `https://s3.eu-central-003.backblazeb2.com`), а для MinIO — ще `BACKUP_S3_PATH_STYLE=true`.
- Одразу після налаштування виконати обидві команди й переконатися, що файли з'явились у бакеті:

```bash
sudo -u www-data php artisan spread:backup
sudo -u www-data php artisan spread:backup:verify
```

- **Відновлення після аварії** (нова база `spread`, той самий користувач):

```bash
pg_restore --no-owner --no-privileges --exit-on-error -h 127.0.0.1 -U spread -d spread spread-YYYYMMDD-HHMMSS.dump
```

## 7. Зовнішній нагляд

Ці сервіси працюють поза сервером, тож помітять і його повне падіння.

- **UptimeRobot** (або аналог): HTTP-монітор `https://<домен>/health` з інтервалом 1 хв. Очікується відповідь `200` і текст `OK`. Сповіщення — у Telegram. `503` означає, що Redis недоступний або немає знімка конфігу, тобто редірект не працює (docs/contract.md).
- **Healthchecks.io**, дві перевірки:
  - «Scheduler»: період 1 хв, grace 5 хв → URL у `HEALTHCHECKS_SCHEDULER_URL`. Пінгує кожна успішна щохвилинна перевірка алертів, тож тиша означає, що зупинився cron, БД чи сам Laravel.
  - «Backup»: період 1 день, grace 2 год → URL у `HEALTHCHECKS_BACKUP_URL`. Пінгує кожен успішний бекап.
  - Канал сповіщень — Telegram-інтеграція Healthchecks.
- **Telegram-алерти трекера** (сторінка «Alerts» в адмінці): chat_id і тестове повідомлення. Помилки рівня error із логів Laravel теж приходять у цей чат.

## 8. Перевірка перед вихідними

- [ ] `curl https://<домен>/health` → `OK`; UptimeRobot зелений.
- [ ] Healthchecks: обидві перевірки зелені.
- [ ] `systemctl status spread-clicks spread-postbacks spread-horizon` → `active (running)`.
- [ ] Учорашній бекап є в бакеті, останній `spread:backup:verify` → код 0.
- [ ] Тестове повідомлення з «Alerts» прийшло.
