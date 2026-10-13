# Tasks: 07-reliability

Скорочення в перевірках: `W` означає `docker exec -u laradock -w /var/www/Spread laradock_all-workspace-85-1`, `P` — `docker exec laradock_all-postgres-1 psql -U default -d spread -tAc`.
Гілки: реалізація йде на `feature/07-reliability`, кожна задача з кодом (група 2) — в окремій `task/07-reliability/<what>` (назву вказано в дужках). Групи 1 і 3 виконуються на feature-гілці; зміни в Laradock — поза репозиторієм.
Кожна задача з кодом закінчується тим, що `W php artisan test` і `W ./vendor/bin/pint --test` зелені. Нижче вказано лише специфічні перевірки.

## 1. Інфраструктура (агент з дозволу користувача; перебудова — користувач)

Група йде першою: тести бекапу запускають справжній `pg_dump` проти PostgreSQL 18.

- [x] 1.1 Агент: бекап `laradock/workspace/Dockerfile` → `Dockerfile.bak-2026-10-14`, блок встановлення `postgresql-client-18` з `apt.postgresql.org` (D9). **Користувач:** перебудувати й перезапустити workspace-85. Перевірка: `W pg_dump --version` → 18.x; `W sh -c 'PGPASSWORD=secret pg_dump -h postgres -U default -d spread_testing --schema-only' | head -3` без «version mismatch».
  - 2026-10-14, агент: бекап `laradock/workspace/Dockerfile.bak-2026-10-14`, після блоку `INSTALL_PG_CLIENT` — встановлення `postgresql-client-18` з `apt.postgresql.org` (`pg_wrapper` бере найновіший клієнт). Перебудову workspace-85 користувач доручив агенту: `docker compose build workspace-85 && up -d` → `pg_dump`/`pg_restore` 18.6; дамп `spread_testing` без «version mismatch»; `redis`, `bcmath`, `pgsql` на місці; 565 тестів зелені.
- [x] 1.2 Агент: запустити MinIO з Laradock, створити бакет `spread-backups` і додати `BACKUP_S3_*` у `.env` проєкту (D9). Перевірка: `W php artisan tinker --execute "Storage::disk('backups')->put('probe.txt', 'ok'); echo Storage::disk('backups')->get('probe.txt');"` → `ok` (після задачі 2.2, де з'являється диск).
  - 2026-10-14: образ `minio/minio` зник з Docker Hub (і з quay.io без авторизації), тож `laradock/minio/Dockerfile` (бекап `.bak-2026-10-14`) переведено на `pgsty/minio:latest` — збірка того самого MinIO від спільноти (RELEASE.2026-08-04); зібрано й запущено агентом на прохання користувача. Бакет `spread-backups` створено через `mc`; з workspace `http://minio:9000/minio/health/live` → 200; `BACKUP_S3_*` додано в `.env`. Після 2.2: `Storage::disk('backups')->put/get('probe.txt')` → `ok`; `spread:backup` поклав `db/*.dump` і `.json` у бакет (видно через `mc ls`).
- [x] 1.3 Агент: `limit_req_zone` і `location ^~ /p/` у `laradock/nginx/sites/85_spread.conf` (бекап `.bak-2026-10-14`), `nginx -t` і `nginx -s reload` (D8, D9). Перевірка: `docker exec laradock_all-nginx-1 nginx -t` — `syntax is ok`.
  - 2026-10-14, агент: `85_spread.conf` (бекап `.bak-2026-10-14`) — `limit_req_zone … rate=20r/s` на рівні http і `location ^~ /p/` з `burst=100 nodelay`, `limit_req_status 429`; `nginx -t` ok, `nginx -s reload`; `/p/` → 200, `/c/` → 302. Навантажувальна перевірка — 3.6.

## 2. Код

- [x] 2.1 (`task/07-reliability/health`) `App\HotPath\HealthController`, маршрут `/health`, розділ у docs/contract.md у тому ж коміті (D1). Тести за spec monitoring «Health-ендпоінт»:
  - Тексти 503 — `no config snapshot` і `redis unavailable`; розділ «Health» у docs/contract.md у тому ж коміті. Тест «Redis недоступний» підміняє `RedisManager` (як у 06-alerts), «БД недоступна» — `pgsql.host` на неіснуючий хост. Живий `curl` → `200 OK`, `Cache-Control: no-store, private`, без `Set-Cookie`.
  - знімок є → `200 OK`, `Cache-Control: no-store`, без `Set-Cookie`;
  - немає `meta` → `503`;
  - Redis недоступний (підмінений `RedisManager`) → `503`;
  - БД недоступна (`DB_HOST` на неіснуючий хост) → `200`;
  - `HotPathIsolationTest` зелений.
  
  Перевірка: `W php artisan test --filter='Health|HotPathIsolation'` зелений; `curl -s -H 'Host: spread.local' http://localhost/health` → `OK`.
- [x] 2.2 (`task/07-reliability/backup`) `composer require league/flysystem-aws-s3-v3`, диск `backups`, `config/spread.php` (`backup.keep`, шляхи до бінарників), `App\Backups\BackupCreator`, команда `spread:backup`, розклад з пінгом (D2). Тести на `spread_testing` зі справжнім `pg_dump` і `Storage::fake('backups')`:
  - Тести без `RefreshDatabase`: `pg_dump` бачить лише закомічені дані, тож тест комітить свій рядок і прибирає його; каталог бекапів — `spread.backup.path` (тимчасовий у тестах), `BACKUP_S3_BUCKET` порожній у `phpunit.xml`. Окремого тесту «запис під час дампу» немає — те, що маніфест і дамп з одного знімка, перевіряє звірка відновленого дампу з маніфестом у 2.3. Віддалені копії — у `db/` на диску `backups`. Невдача вивантаження теж дає код 1 і `error`, але локальний дамп лишається. На `spread`: `spread:backup` → 176 КБ дамп + маніфест локально й у MinIO.
  - дамп і маніфест створено, кількості в маніфесті = кількостям у базі;
  - запис, зроблений під час дампу іншим з'єднанням, не порушує збіг маніфесту з дампом (snapshot);
  - ротація до N локально й на диску;
  - диск не налаштовано → лише локально;
  - `pg_dump` з неіснуючим шляхом → код 1, `Log::error`, жодного `.part` чи `.dump`;
  - `schedule:list` містить бекап щодня з пінгом.
  
  Перевірка: `W php artisan test --filter='BackupCreat|SpreadBackup'` зелений; `W php artisan spread:backup` → файли в `storage/app/backups/`.
- [x] 2.3 (`task/07-reliability/backup-verify`) `App\Backups\BackupVerifier`, команда `spread:backup:verify`, розклад щотижня (D3). Тести:
  - Бекап із диска завантажується в `<backup.path>/verify/`. Звірка відновленого дампу з маніфестом доводить, що маніфест і дамп з одного знімка (тест на змінену кількість падає). На `spread`: `spread:backup:verify` відновив дамп у `spread_verify_*`, усі таблиці збіглися з маніфестом (clicks, report_stats 1017, …), тимчасових баз не лишилось.
  - бекап → перевірка → код 0, вивід таблиць, тимчасової бази немає (`pg_database`);
  - обрізаний файл → код 1, `Log::error`, тимчасової бази немає;
  - змінена кількість у маніфесті → код 1;
  - локальних немає, на фейковому диску є → завантажено й перевірено;
  - робоча база не змінилась.
  
  Перевірка: `W php artisan test --filter=BackupVerif` зелений.
- [x] 2.4 (`task/07-reliability/clicks-archive`) Міграція `click_archives`, `App\Clicks\ClickArchiver`, команда `spread:clicks:archive {--months=6}`, розклад щомісяця, пропуск архівованих місяців у `StatsAggregator` (D4). Тести за spec clicks-archive:
  - Шматки перерахунку в `StatsAggregator` тепер ріжуться ще й по межах місяців, і шматок заархівованого місяця (з `click_archives`) пропускається; `rebuildAll()` видаляє рядки поза діапазоном кліків лише для незаархівованих місяців. Каталог архіву — `spread.archive.path`; копія на диску `backups` у `archive/`. Видалення — пачками по 5000 з тією самою умовою «без конверсії». Під час задачі завис тестовий запуск: процеси `php` пережили перезапуск контейнера й тримали «idle in transaction» у `spread_testing`; після `pkill` і `pg_terminate_backend` усе стабільно.
  - 1000 кліків за 2026-03, 10 з конверсіями, зараз 2026-10 → у `.csv.gz` 990 рядків + заголовок, у базі 10, запис у `click_archives`;
  - 2026-04 не зачеплено;
  - статистика й звіт за 2026-03 до і після однакові;
  - `aggregate --all` і `--from=2026-03-01 --to=2026-04-30` не змінюють 2026-03, а 2026-04 перераховано;
  - повторна архівація дає `clicks-2026-03-2.csv.gz`;
  - файл не записався (каталог лише для читання) → кліки лишились, код 1;
  - копія на фейковий диск `backups`.
  
  Перевірка: `W php artisan test --filter='ClickArchiv|StatsAggregator'` зелений.
- [x] 2.5 (`task/07-reliability/two-factor`) Міграція колонок 2FA, `User` з контрактами й шифруванням, MFA в `AdminPanelProvider`, `Horizon::auth` (D5). Тести за spec admin-access «Двофакторна автентифікація»:
  - Модель — трейти Filament `InteractsWithAppAuthentication(Recovery)` (касти `encrypted`, `encrypted:array`, колонки приховані). Фабрика за замовчуванням дає користувачу секрет (адмінка вимагає 2FA), стан `withoutTwoFactor()` — для нового користувача; `AdminAccessTest` «вхід з правильним паролем» тепер про користувача без 2FA (пароль приймається, далі — налаштування). Сценарій налаштування через дію Filament перевіряється в браузері (3.4); тести покривають редирект, вхід з кодом, неправильний код, код відновлення один раз, шифрування, Horizon 403/200.
  - користувач без 2FA після входу → редирект на налаштування з будь-якої сторінки;
  - налаштування з правильним кодом (`Google2FA` у тесті) зберігає секрет і коди відновлення зашифрованими (сирі колонки не містять секрету);
  - вхід з правильним кодом;
  - неправильний код відхилено;
  - код відновлення працює один раз;
  - `/horizon` без 2FA → 403, з 2FA → 200;
  - наявні тести адмінки зелені (у фабриці чи хелпері тестів користувач має 2FA).
  
  Перевірка: `W php artisan test --filter='TwoFactor|AdminAccess|HorizonAccess'` і `W php artisan test` зелені.
- [x] 2.6 (`task/07-reliability/log-telegram`) `App\Logging\TelegramLogHandler`, канал `telegram`, `LOG_STACK`/`LOG_DAILY_DAYS` у `.env.example` (D6). Тести з `Http::fake()`:
  - Додатково: `phpunit.xml` задає `TELEGRAM_BOT_TOKEN=""` і `LOG_STACK=single` — без цього тести брали справжній токен з `.env` і з новим каналом могли б слати реальні повідомлення. Текст — `🚨 <b>ERROR</b>` + `<pre>` з класом, повідомленням і місцем, обрізаний до 3500 символів. `.env` локально теж переведено на `LOG_STACK=daily,telegram`, `LOG_DAILY_DAYS=14`.
  - `Log::error` з винятком → одне повідомлення з класом, текстом, файлом і рядком;
  - 50 однакових за 5 хв → одне, через 11 хв → друге;
  - `warning` → нічого;
  - Telegram 500 → виняток не вилітає, запис у файловому лозі є;
  - БД недоступна → chat_id з файлового кешу;
  - повідомлення довше 4096 обрізано;
  - HTML у тексті екрановано.
  
  Перевірка: `W php artisan test --filter=TelegramLog` зелений.
- [x] 2.7 (`task/07-reliability/heartbeat`) `services.healthchecks.scheduler_url`/`backup_url`, `.env.example`, `pingOnSuccessIf` для `spread:alerts:check` (D7). Тести:
  - Пінг — `->pingOnSuccessIf()` на `spread:alerts:check`; Laravel шле його Guzzle-клієнтом з контейнера, тож тест підставляє `GuzzleHttp\Client` з `MockHandler` і перебудовує розклад з `routes/console.php` (`Schedule` фасад через `swap`). Невдалий запуск не пінгує. Помилку самого пінгу Laravel передає в `report()`, тобто вона дійде в Telegram через канал логів (з тротлінгом).
  - URL задано → подія розкладу має пінг (через `Schedule` подію та `Http::fake()` при виконанні callback);
  - URL порожній → пінгу немає.
  
  Перевірка: `W php artisan test --filter=Heartbeat` зелений.
- [x] 2.8 (`task/07-reliability/deploy-files`) `deploy/cron/spread`, `deploy/systemd/*.service`, `deploy/nginx/spread.conf`, `docs/deploy.md` (D8). Перевірка: `docker exec laradock_all-nginx-1 nginx -t -c` на тимчасовому `nginx.conf`, що підключає `deploy/nginx/spread.conf`, → `syntax is ok`; у `docs/deploy.md` є кроки для `.env`, `CREATEDB`, UptimeRobot, Healthchecks, S3, 2FA-відновлення.
  - Синтаксис `deploy/nginx/spread.conf` перевірено `nginx -t` у контейнері Laradock на копії, де HTTPS-блок слухає 8443 без сертифікатів, а `include fastcgi_params` абсолютний (варіант із самопідписаним сертифікатом через `sh -c` зупинила вбудована перевірка безпеки); перевірочні файли лишились у `/tmp` контейнера nginx. Перший користувач адмінки — `make:filament-user` (окремої `spread:`-команди немає). systemd-юніти: `KillSignal=SIGTERM`, `Restart=always`.

## 3. Ручна перевірка «Готово, коли» (агент з дозволу користувача)

- [x] 3.1 `/health`: `curl` → `200 OK`. Перейменувати `spread:config:meta` у Redis → `503`; `W php artisan spread:config:rebuild` → знову `200`.
  - 2026-10-14, агент: `curl /health` → `OK 200`; `RENAME spread:config:meta` → `no config snapshot 503`; `spread:config:rebuild` → `OK 200`; перейменований ключ видалено.
- [x] 3.2 Бекап: `W php artisan spread:backup` → дамп локально й у MinIO (перевірити в консолі MinIO або `Storage::disk('backups')->files()`). `W php artisan spread:backup:verify` → код 0, таблиці збіглись. Видалити локальні бекапи → `spread:backup:verify` бере з MinIO → код 0.
  - 2026-10-14, агент: `spread:backup` → `spread-20261013-221850` (179 КБ) локально й у MinIO (`db/`, видно через `mc ls`); `spread:backup:verify` → усі таблиці збіглися, код 0. З `SPREAD_BACKUP_PATH` на порожній каталог перевірка завантажила останній бекап з MinIO у `…/verify/` і пройшла; тимчасових баз `spread_verify_*` — 0. Каталог `storage/app/backup-remote-check/` лишився (gitignored).
- [x] 3.3 Архів (з дозволу користувача, бо видаляє локальні тестові кліки; перед цим зроблено бекап 3.2): `W php artisan spread:clicks:archive --months=0` → архівовано вересень 2026 (якщо є кліки), «Reports» за вересень показує ті самі цифри, що й до архівації. `spread:reports:aggregate --all` не змінює вересень.
  - 2026-10-14, агент: локально всі кліки були жовтневі, тож для перевірки додано 50 тестових кліків «Load test 6.1» за 2026-09-15 (зони `sep-a` 30, `sep-b` 20) і одну конверсію approved 7 USD; `aggregate --from=2026-09-01 --to=2026-09-30` → звіт за вересень: sep-a 30/1/7.00, sep-b 20/0/0. `spread:clicks:archive --months=0` → «2026-09: 49 clicks archived to clicks-2026-09.csv.gz» (заголовок + 49 рядків, копія в MinIO `archive/`), у базі за вересень лишився 1 клік з конверсією, запис у `click_archives`. `spread:reports:aggregate --all` → звіт за вересень без змін (sep-a 30/1/7.00, sep-b 20), жовтень 6074 = кліки в базі.
- [x] 3.4 2FA у браузері (Chrome на Windows, тимчасовий користувач): вхід → сторінка налаштування з QR → код з секрету → коди відновлення → вихід і вхід з кодом → код відновлення один раз. Користувач налаштовує 2FA для свого облікового запису сам.
  - 2026-10-14, агент у Chrome на Windows (headless, тимчасовий користувач без 2FA): вхід паролем → `/admin/multi-factor-authentication/set-up`, `/admin/campaigns` теж веде туди; «Set up» → секрет текстом і QR, код TOTP пораховано зі секрету, пароль → 8 кодів відновлення → «Enable authenticator app» → «Enabled»; у `users` секрет зашифрований (`eyJpdiI6…`). Новий вхід: `000000` → лишився на логіні, свіжий код → `/admin`; код відновлення → `/admin`, той самий удруге → відмова. Користувач налаштовує 2FA для свого облікового запису сам (у `spread` зараз без 2FA — при першому вході адмінка попросить налаштувати).
- [ ] 3.5 Помилки в Telegram: `W php artisan tinker --execute "report(new RuntimeException('07-reliability check'));"` → повідомлення в Telegram (підтверджує користувач); вдруге одразу → не прийшло.
- [x] 3.6 Ліміт постбеків: 300 паралельних запитів `curl` на `/p/<key>` → частина `429`, кількість у `spread:postbacks` = кількості `200`; 300 на `/c/<alias>` з `HEAD` → жодного `429`.
  - 2026-10-14, агент: 300 паралельних `GET /p/ratelimitcheck` (60 потоків) → 118×200, 182×429; `spread:postbacks` +118 — рівно прийняті; воркер розібрав їх як `rejected / invalid_key`. 300 паралельних `HEAD /c/5yskllrww5` → 300×302, жодного 429.
- [ ] 3.7 Пінг планувальника: `HEALTHCHECKS_SCHEDULER_URL=http://spread.local/health` у `.env`, `W php artisan schedule:work` на 3 хвилини → у лозі nginx 3 запити `GET /health` від php-контейнера; потім повернути порожнє значення.
- [ ] 3.8 «Готово, коли»: `W php artisan schedule:work` на 10 хвилин із запущеними воркерами й кількома кліками й постбеками через `curl`. Перевірка:
  - у лозі немає `error`;
  - `spread:alerts:check` і `spread:reports:aggregate` відпрацьовують за розкладом;
  - буфери порожні.
  
  Записати, що на VPS лишається зробити за `docs/deploy.md` (етап 0).
