# Tasks: 07-reliability

Скорочення в перевірках: `W` означає `docker exec -u laradock -w /var/www/Spread laradock_all-workspace-85-1`, `P` — `docker exec laradock_all-postgres-1 psql -U default -d spread -tAc`.
Гілки: реалізація йде на `feature/07-reliability`, кожна задача з кодом (група 2) — в окремій `task/07-reliability/<what>` (назву вказано в дужках). Групи 1 і 3 виконуються на feature-гілці; зміни в Laradock — поза репозиторієм.
Кожна задача з кодом закінчується тим, що `W php artisan test` і `W ./vendor/bin/pint --test` зелені. Нижче вказано лише специфічні перевірки.

## 1. Інфраструктура (агент з дозволу користувача; перебудова — користувач)

Група йде першою: тести бекапу запускають справжній `pg_dump` проти PostgreSQL 18.

- [ ] 1.1 Агент: бекап `laradock/workspace/Dockerfile` → `Dockerfile.bak-2026-10-14`, блок встановлення `postgresql-client-18` з `apt.postgresql.org` (D9). **Користувач:** перебудувати й перезапустити workspace-85. Перевірка: `W pg_dump --version` → 18.x; `W sh -c 'PGPASSWORD=secret pg_dump -h postgres -U default -d spread_testing --schema-only' | head -3` без «version mismatch».
- [ ] 1.2 Агент: запустити MinIO з Laradock, створити бакет `spread-backups` і додати `BACKUP_S3_*` у `.env` проєкту (D9). Перевірка: `W php artisan tinker --execute "Storage::disk('backups')->put('probe.txt', 'ok'); echo Storage::disk('backups')->get('probe.txt');"` → `ok` (після задачі 2.2, де з'являється диск).
- [ ] 1.3 Агент: `limit_req_zone` і `location ^~ /p/` у `laradock/nginx/sites/85_spread.conf` (бекап `.bak-2026-10-14`), `nginx -t` і `nginx -s reload` (D8, D9). Перевірка: `docker exec laradock_all-nginx-1 nginx -t` — `syntax is ok`.

## 2. Код

- [ ] 2.1 (`task/07-reliability/health`) `App\HotPath\HealthController`, маршрут `/health`, розділ у docs/contract.md у тому ж коміті (D1). Тести за spec monitoring «Health-ендпоінт»:
  - знімок є → `200 OK`, `Cache-Control: no-store`, без `Set-Cookie`;
  - немає `meta` → `503`;
  - Redis недоступний (підмінений `RedisManager`) → `503`;
  - БД недоступна (`DB_HOST` на неіснуючий хост) → `200`;
  - `HotPathIsolationTest` зелений.
  
  Перевірка: `W php artisan test --filter='Health|HotPathIsolation'` зелений; `curl -s -H 'Host: spread.local' http://localhost/health` → `OK`.
- [ ] 2.2 (`task/07-reliability/backup`) `composer require league/flysystem-aws-s3-v3`, диск `backups`, `config/spread.php` (`backup.keep`, шляхи до бінарників), `App\Backups\BackupCreator`, команда `spread:backup`, розклад з пінгом (D2). Тести на `spread_testing` зі справжнім `pg_dump` і `Storage::fake('backups')`:
  - дамп і маніфест створено, кількості в маніфесті = кількостям у базі;
  - запис, зроблений під час дампу іншим з'єднанням, не порушує збіг маніфесту з дампом (snapshot);
  - ротація до N локально й на диску;
  - диск не налаштовано → лише локально;
  - `pg_dump` з неіснуючим шляхом → код 1, `Log::error`, жодного `.part` чи `.dump`;
  - `schedule:list` містить бекап щодня з пінгом.
  
  Перевірка: `W php artisan test --filter='BackupCreat|SpreadBackup'` зелений; `W php artisan spread:backup` → файли в `storage/app/backups/`.
- [ ] 2.3 (`task/07-reliability/backup-verify`) `App\Backups\BackupVerifier`, команда `spread:backup:verify`, розклад щотижня (D3). Тести:
  - бекап → перевірка → код 0, вивід таблиць, тимчасової бази немає (`pg_database`);
  - обрізаний файл → код 1, `Log::error`, тимчасової бази немає;
  - змінена кількість у маніфесті → код 1;
  - локальних немає, на фейковому диску є → завантажено й перевірено;
  - робоча база не змінилась.
  
  Перевірка: `W php artisan test --filter=BackupVerif` зелений.
- [ ] 2.4 (`task/07-reliability/clicks-archive`) Міграція `click_archives`, `App\Clicks\ClickArchiver`, команда `spread:clicks:archive {--months=6}`, розклад щомісяця, пропуск архівованих місяців у `StatsAggregator` (D4). Тести за spec clicks-archive:
  - 1000 кліків за 2026-03, 10 з конверсіями, зараз 2026-10 → у `.csv.gz` 990 рядків + заголовок, у базі 10, запис у `click_archives`;
  - 2026-04 не зачеплено;
  - статистика й звіт за 2026-03 до і після однакові;
  - `aggregate --all` і `--from=2026-03-01 --to=2026-04-30` не змінюють 2026-03, а 2026-04 перераховано;
  - повторна архівація дає `clicks-2026-03-2.csv.gz`;
  - файл не записався (каталог лише для читання) → кліки лишились, код 1;
  - копія на фейковий диск `backups`.
  
  Перевірка: `W php artisan test --filter='ClickArchiv|StatsAggregator'` зелений.
- [ ] 2.5 (`task/07-reliability/two-factor`) Міграція колонок 2FA, `User` з контрактами й шифруванням, MFA в `AdminPanelProvider`, `Horizon::auth` (D5). Тести за spec admin-access «Двофакторна автентифікація»:
  - користувач без 2FA після входу → редирект на налаштування з будь-якої сторінки;
  - налаштування з правильним кодом (`Google2FA` у тесті) зберігає секрет і коди відновлення зашифрованими (сирі колонки не містять секрету);
  - вхід з правильним кодом;
  - неправильний код відхилено;
  - код відновлення працює один раз;
  - `/horizon` без 2FA → 403, з 2FA → 200;
  - наявні тести адмінки зелені (у фабриці чи хелпері тестів користувач має 2FA).
  
  Перевірка: `W php artisan test --filter='TwoFactor|AdminAccess|HorizonAccess'` і `W php artisan test` зелені.
- [ ] 2.6 (`task/07-reliability/log-telegram`) `App\Logging\TelegramLogHandler`, канал `telegram`, `LOG_STACK`/`LOG_DAILY_DAYS` у `.env.example` (D6). Тести з `Http::fake()`:
  - `Log::error` з винятком → одне повідомлення з класом, текстом, файлом і рядком;
  - 50 однакових за 5 хв → одне, через 11 хв → друге;
  - `warning` → нічого;
  - Telegram 500 → виняток не вилітає, запис у файловому лозі є;
  - БД недоступна → chat_id з файлового кешу;
  - повідомлення довше 4096 обрізано;
  - HTML у тексті екрановано.
  
  Перевірка: `W php artisan test --filter=TelegramLog` зелений.
- [ ] 2.7 (`task/07-reliability/heartbeat`) `services.healthchecks.scheduler_url`/`backup_url`, `.env.example`, `pingOnSuccessIf` для `spread:alerts:check` (D7). Тести:
  - URL задано → подія розкладу має пінг (через `Schedule` подію та `Http::fake()` при виконанні callback);
  - URL порожній → пінгу немає.
  
  Перевірка: `W php artisan test --filter=Heartbeat` зелений.
- [ ] 2.8 (`task/07-reliability/deploy-files`) `deploy/cron/spread`, `deploy/systemd/*.service`, `deploy/nginx/spread.conf`, `docs/deploy.md` (D8). Перевірка: `docker exec laradock_all-nginx-1 nginx -t -c` на тимчасовому `nginx.conf`, що підключає `deploy/nginx/spread.conf`, → `syntax is ok`; у `docs/deploy.md` є кроки для `.env`, `CREATEDB`, UptimeRobot, Healthchecks, S3, 2FA-відновлення.

## 3. Ручна перевірка «Готово, коли» (агент з дозволу користувача)

- [ ] 3.1 `/health`: `curl` → `200 OK`. Перейменувати `spread:config:meta` у Redis → `503`; `W php artisan spread:config:rebuild` → знову `200`.
- [ ] 3.2 Бекап: `W php artisan spread:backup` → дамп локально й у MinIO (перевірити в консолі MinIO або `Storage::disk('backups')->files()`). `W php artisan spread:backup:verify` → код 0, таблиці збіглись. Видалити локальні бекапи → `spread:backup:verify` бере з MinIO → код 0.
- [ ] 3.3 Архів (з дозволу користувача, бо видаляє локальні тестові кліки; перед цим зроблено бекап 3.2): `W php artisan spread:clicks:archive --months=0` → архівовано вересень 2026 (якщо є кліки), «Reports» за вересень показує ті самі цифри, що й до архівації. `spread:reports:aggregate --all` не змінює вересень.
- [ ] 3.4 2FA у браузері (Chrome на Windows, тимчасовий користувач): вхід → сторінка налаштування з QR → код з секрету → коди відновлення → вихід і вхід з кодом → код відновлення один раз. Користувач налаштовує 2FA для свого облікового запису сам.
- [ ] 3.5 Помилки в Telegram: `W php artisan tinker --execute "report(new RuntimeException('07-reliability check'));"` → повідомлення в Telegram (підтверджує користувач); вдруге одразу → не прийшло.
- [ ] 3.6 Ліміт постбеків: 300 паралельних запитів `curl` на `/p/<key>` → частина `429`, кількість у `spread:postbacks` = кількості `200`; 300 на `/c/<alias>` з `HEAD` → жодного `429`.
- [ ] 3.7 Пінг планувальника: `HEALTHCHECKS_SCHEDULER_URL=http://spread.local/health` у `.env`, `W php artisan schedule:work` на 3 хвилини → у лозі nginx 3 запити `GET /health` від php-контейнера; потім повернути порожнє значення.
- [ ] 3.8 «Готово, коли»: `W php artisan schedule:work` на 10 хвилин із запущеними воркерами й кількома кліками й постбеками через `curl`. Перевірка:
  - у лозі немає `error`;
  - `spread:alerts:check` і `spread:reports:aggregate` відпрацьовують за розкладом;
  - буфери порожні.
  
  Записати, що на VPS лишається зробити за `docs/deploy.md` (етап 0).
