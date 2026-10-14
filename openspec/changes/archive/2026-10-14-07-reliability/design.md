# Design: 07-reliability

## Context

- Гарячий шлях (`app/HotPath`, `routes/hot-path.php`) читає лише Redis-з'єднання `spread` і перевіряється тестом `HotPathIsolationTest`. Знімок конфігу — `spread:config:meta`.
- `StatsAggregator::aggregate()` і `rebuildAll()` (05-reports) перебудовують `report_stats` з таблиці `clicks`. `rebuildAll()` ще й видаляє рядки поза діапазоном наявних кліків.
- `TelegramClient::send($html, ?$chatId)` (06-alerts) бере chat_id з `AlertSetting`, тобто з БД, якщо його не передано.
- Horizon пускає будь-якого автентифікованого (`Horizon::auth`). Filament 5 має `Panel::multiFactorAuthentication([...], isRequired: true)` і контракти `HasAppAuthentication`, `HasAppAuthenticationRecovery`. `pragmarx/google2fa` уже в `vendor`.
- Локально:
  - у workspace-85 `pg_dump` 16, а сервер PostgreSQL 18; дамп падає з «server version mismatch»;
  - MinIO є в Laradock, але не запущений;
  - nginx спільний, сайт — `laradock/nginx/sites/85_spread.conf`, а `server` з нього підключається всередині блоку `http`.
- Планувальник: `routes/console.php`, cron локально не запущений.

Мотивація й рішення користувача — у proposal.md, вимоги — у specs/.

## Goals / Non-Goals

**Goals:**
- Кожен механізм перевіряється локально по-справжньому: дамп і відновлення на PostgreSQL 18, вивантаження на MinIO, 429 від nginx, код TOTP у браузері.
- Серверні налаштування — це файли в репозиторії, які застосовуються на VPS без змін, крім шляхів і доменів.

**Non-Goals:**
- Автоматичний деплой (CI/CD, Ansible). Інструкція в `docs/deploy.md` ручна.
- Відновлення на довільний момент часу (WAL), реплікація.

## Decisions

### D1. `/health`
`App\HotPath\HealthController`:
- `GET spread:config:meta` через з'єднання `spread`;
- рядок є → `200 OK`; ключа немає → `503 no config snapshot`; `RedisException` → `503 redis unavailable`;
- `text/plain`, `Cache-Control: no-store`.

Маршрут у `routes/hot-path.php` (поза групою `web`, без сесії). Розділ у docs/contract.md змінюється в тому ж коміті, що й код.

### D2. Бекап
`App\Backups\BackupCreator`, команда `spread:backup`:
1. Окреме PDO-з'єднання з БД (`DB::connection()->getPdo()`, щоб не змішувати транзакції з рештою коду):
   - `BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY`;
   - `SELECT pg_export_snapshot()`;
   - `count(*)` кожної таблиці схеми `public` (з `pg_tables`).
2. Поки транзакція відкрита — `pg_dump --format=custom --snapshot=<id> --no-owner --file=<name>.dump.part` через Symfony `Process` (пароль у `PGPASSWORD`, не в аргументах). Так маніфест і дамп бачать рівно ті самі дані.
3. `COMMIT`. Успіх → `rename .part → .dump` і запис `<name>.json` (`created_at`, `size`, `tables`). Помилка → видалення `.part`, `Log::error`, код 1.
4. Ротація локально (`storage/app/backups`): лишаються N найновіших за іменем (`config('spread.backup.keep')`, 14).
5. Якщо налаштовано диск `backups` (`config('filesystems.disks.backups.bucket')` не порожній) — `putFileAs` дампу й маніфесту, потім та сама ротація серед `.dump` на диску.

Диск `backups` у `config/filesystems.php`: драйвер `s3`, змінні `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, `BACKUP_S3_REGION`, `BACKUP_S3_BUCKET`, `BACKUP_S3_ENDPOINT`, `use_path_style_endpoint` (для MinIO). Пакет `league/flysystem-aws-s3-v3`. Шляхи до бінарників — `config('spread.backup.pg_dump')` і `pg_restore` (за замовчуванням з `PATH`).

Розклад: `spread:backup` щодня о 03:00 Europe/Kyiv, `->pingOnSuccessIf(filled($url), $url)` з `HEALTHCHECKS_BACKUP_URL`.

### D3. Перевірка відновлення
`App\Backups\BackupVerifier`, команда `spread:backup:verify`:
- Береться останній локальний `.dump` + `.json`. Якщо їх немає — останній з диска `backups`, завантажений у `storage/app/backups/verify/`.
- Тимчасова база `spread_verify_<YmdHis>`: `CREATE DATABASE` на основному з'єднанні (користувачеві БД потрібне право `CREATEDB`; на VPS це в `docs/deploy.md`).
- `pg_restore --no-owner --no-privileges --exit-on-error -d <db>`. Далі runtime-з'єднання `verify` (копія `pgsql` з іншою базою через `config()->set` + `DB::purge`) рахує `count(*)` таблиць з маніфесту.
- `finally`: `DROP DATABASE … WITH (FORCE)`.

Розклад: щонеділі о 04:00 Europe/Kyiv.

### D4. Архів кліків
- Таблиця `click_archives`: `id`, `month` date (перше число), `clicks` int, `file` string, `archived_at`.
- `App\Clicks\ClickArchiver::archiveMonth(CarbonImmutable $month)`:
  - курсор по `clicks where clicked_at in [month, month+1) and not exists conversion` → `gzopen(storage/app/archive/clicks-YYYY-MM[-N].csv.gz, 'wb9')` + `fputcsv`;
  - `gzclose` успішний → копія на диск `backups` у `archive/` (якщо налаштовано);
  - далі видалення пачками по 5000: `delete … where click_id in (select … limit 5000)` з тією самою умовою `not exists conversion`. Клік, що встиг отримати конверсію між експортом і видаленням, лишається в базі й у файлі; дубль в архіві не шкодить;
  - запис у `click_archives`.
- Команда `spread:clicks:archive {--months=6}` обробляє місяці від найстарішого кліку до `startOfMonth − months`. Розклад: 1-го числа о 04:30 Europe/Kyiv.
- `StatsAggregator`: перед кожною добою пропускає години, що належать місяцям із `click_archives` (`archivedMonths()` один раз на прогін). `rebuildAll()` видаляє рядки поза діапазоном кліків лише для незаархівованих місяців. Альтернатива «межа = останній архівований місяць» відкинута: місяць без кліків на момент архівації лишився б незаархівованим, і межа заморозила б його неправильно.

### D5. 2FA
- Міграція в `users`: `app_authentication_secret` text nullable, `app_authentication_recovery_codes` text nullable.
- `User` реалізує `HasAppAuthentication`, `HasAppAuthenticationRecovery`; касти `encrypted`, `encrypted:array`; `$hidden`.
- `AdminPanelProvider`: `->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)`.
- `Horizon::auth`: `$request->user()?->getAppAuthenticationSecret() !== null`. Filament завершує вхід лише після коду, тож автентифікований користувач із секретом уже пройшов 2FA.
- Наявний користувач після деплою потрапляє на налаштування 2FA при першому вході.

### D6. Помилки в Telegram
- `App\Logging\TelegramLogHandler extends AbstractProcessingHandler` (рівень `Level::Error`). Канал `telegram` (`driver => monolog`, `handler => TelegramLogHandler::class`). `.env.example`: `LOG_STACK=daily,telegram`, `LOG_DAILY_DAYS=14`.
- Текст: `🚨 <b>{LEVEL}</b> {message}`, для винятку — `{class} at {file}:{line}`. Усе екрановано `e()` і обрізано до 3500 символів.
- Тротлінг: `Cache::store('file')->add('spread:log-alert:'.sha1(message|file|line), 1, 600)`. Файловий кеш, бо помилка може бути саме в БД (кеш застосунку — `database`).
- chat_id: `AlertSetting::current()->chat_id` у `try`. Успіх записується у файловий кеш `spread:alerts:chat_id`, помилка → значення з кешу.
- Відправлення — `TelegramClient::send($text, $chatId)`. Будь-який `Throwable` ковтається. Статичний прапорець не дає піти в рекурсію, якщо під час відправлення щось знову пише `error`.

### D7. Пінг планувальника
`spread:alerts:check` у `routes/console.php` отримує `->pingOnSuccessIf(filled($url), $url)` з `config('services.healthchecks.scheduler_url')`. Laravel сам ковтає помилки пінгу (`Event::pingCallback`). Значення конфігу читаються під час побудови розкладу: це конфіг, а не БД.

### D8. Файли деплою
- `deploy/cron/spread`: `* * * * * www-data cd /var/www/spread && php artisan schedule:run >> /dev/null 2>&1`.
- `deploy/systemd/spread-clicks.service`, `spread-postbacks.service`, `spread-horizon.service`: `Restart=always`, `KillSignal=SIGTERM`, `TimeoutStopSec=30` (воркери дообробляють пачку за SIGTERM).
- `deploy/nginx/spread.conf`:
  - `limit_req_zone $binary_remote_addr zone=spread_postbacks:10m rate=20r/s;`
  - `location ^~ /p/ { limit_req zone=spread_postbacks burst=100 nodelay; limit_req_status 429; … }`
  - решта як у локальному сайті + HTTPS-заготовка.
- `docs/deploy.md`:
  - пакети (PHP 8.5, PostgreSQL 18 і клієнт, Redis, nginx);
  - `.env`;
  - права `CREATEDB`;
  - встановлення файлів;
  - UptimeRobot на `/health` раз на хвилину з контактом у Telegram;
  - дві перевірки Healthchecks (планувальник: період 1 хв, grace 5 хв; бекап: період 1 день, grace 2 год);
  - S3-бакет і ключі.

### D9. Локальна інфраструктура
- `laradock/workspace/Dockerfile`: блок `apt` з репозиторієм `apt.postgresql.org` і `postgresql-client-18` (бекап файлу `Dockerfile.bak-2026-10-14`). Перебудова `workspace-85` — за користувачем.
- MinIO: `docker compose up -d minio` у Laradock і бакет `spread-backups`. Ключі — у `.env` проєкту.
- `85_spread.conf`: `limit_req_zone` і `location ^~ /p/` як у D8, далі `nginx -t` і `nginx -s reload` у спільному контейнері.

## Risks / Trade-offs

- [Архівовані кліки поза базою] → сторінки кліків і пошук click_id для дуже старих постбеків їх не знайдуть: постбек на клік, старший за 6 місяців, стане сирітським. CPA-мережі так не роблять. Файл зберігає все для ручного розбору.
- [Статистика архівованих місяців заморожена] → зміна курсу чи статусу конверсії старого кліку не перерахує ці місяці. Прийнятно для даних, старших за пів року.
- [Тротлінг у файловому кеші] → на кількох серверах тротлінг був би на кожному свій. У нас один VPS.
- [Тимчасова база для перевірки] → потрібне право `CREATEDB` і вільне місце на диску, порівнянне з базою. Описано в `docs/deploy.md`.
- [Спільний nginx і workspace у Laradock] → зміни зачіпають інші проєкти. Пом'якшення:
  - ліміт діє лише в `server` для `spread.local`;
  - клієнт PostgreSQL 18 сумісний зі старшими серверами;
  - для Dockerfile лишається бекап файлу.
- [2FA і втрата телефону] → коди відновлення. Якщо загублено й їх, `php artisan tinker` на сервері обнуляє секрет. Це записано в `docs/deploy.md`.

## Migration Plan

Міграції: `users` (дві колонки) і `click_archives`. Після деплою:
1. Заповнити `.env`: S3, Healthchecks, `LOG_STACK`.
2. Налаштувати 2FA при першому вході.
3. Виконати `spread:backup` і `spread:backup:verify`.

Відкат: `migrate:rollback`, прибрати MFA з `AdminPanelProvider`, повернути `LOG_STACK=single`.
