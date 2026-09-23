# Design: 02-redirect

## Context

Після 01-skeleton є моделі `TrafficSource`, `CpaNetwork`, `Offer`, `Campaign` і Filament-ресурси до них. Маршрутів, крім `/` і адмінки, немає: `/c/{alias}` через nginx дає 404. Redis підключено лише як черга Horizon з префіксом `laravel-database-` (`config/database.php`, `options.prefix`), тож ключі контракту без окремого з'єднання отримали б цей префікс. У workspace-85 є `pcntl`, `redis`, `intl`; у php-fpm-85 — `redis`, без `pcntl` (редіректу він не потрібен). Redis 8.6, PostgreSQL 18. У WSL немає жодного навантажувального інструмента (`hey`, `ab`, `k6`, `wrk`, `oha` відсутні). У WSL `/etc/hosts` немає `spread.local`, тому з WSL сайт доступний як `http://localhost` із заголовком `Host: spread.local`.

Мотивація — у proposal.md, вимоги — у specs/. Усе, що стосується редіректу, повідомлення кліку, знімка й воркера, звірено з docs/contract.md. Цей change уточнює контракт v1 у місцях, де він мовчав (див. розділ «Уточнення контракту»); формат повідомлень не змінюється, `v` лишається 1.

## Goals / Non-Goals

**Goals:**
- Гарячий шлях, який переноситься на Go без змін у решті Laravel: він спирається лише на ключі й формати контракту.
- Жоден клік, записаний у стрім, не губиться і не дублюється в БД.

**Non-Goals:**
- Метрики, health-check, supervisor/systemd для воркера (етапи 7–8).
- Нормалізація параметрів, звіти, агрегати (етап 5).
- Оптимізація Laravel під високий RPS (кешування конфігу/маршрутів, OPcache-тюнінг, розмір пулу FPM) — лише фіксуємо виміряне.

## Decisions

### D1. Redis-з'єднання `spread`
У `config/database.php` → `redis` додається з'єднання `spread`: той самий host/port/пароль, що й `default`, `database` = `env('SPREAD_REDIS_DB', env('REDIS_DB', '0'))`, `prefix` = `''`, `timeout` = 1.0, `read_timeout` = 5.0, `max_retries` = 0. Порожній `prefix` на рівні з'єднання перекриває глобальний `options.prefix` (перевірено в `PhpRedisConnector::connect()`). Короткий `timeout` потрібен, щоб при недоступному Redis редірект за секунду віддав fallback, а не висів. `read_timeout` 5 с більший за `BLOCK` воркера (2 с). Усі операції з `spread:*` — і в гарячому шляху, і в Laravel-частині — ідуть лише через це з'єднання.

У `phpunit.xml`: `SPREAD_REDIS_DB=15` і `SPREAD_FALLBACK_URL=https://fallback.test/`. Тести, що торкаються Redis, роблять `FLUSHDB` лише на з'єднанні `spread` (тобто в DB 15), локальні дані в DB 0 не зачіпаються.

Альтернатива: два з'єднання (для редіректу і для воркера) з різними таймаутами — відкинуто як зайве; одного з наведеними таймаутами досить.

### D2. Налаштування `config/spread.php`
Додаються `fallback_url` = `env('SPREAD_FALLBACK_URL')` і `geoip_path` = `storage_path('app/geoip/dbip-country-lite.mmdb')`. `.env.example` отримує `SPREAD_FALLBACK_URL=https://example.com/`. Тека `storage/app/geoip/` уже ігнорується git через `storage/app/.gitignore` (`*`).

### D3. Будівник знімка
- Клас у `App\ConfigSnapshot\` (Laravel-частина, Eloquent дозволено): читає всі кампанії з оффером (`with('offer')`), будує поле `alias` → JSON `{"id", "offer_id", "active", "offer_url"}` (`offer_url` = `offers.url_template`) і meta `{"v": 1, "version": <ms>, "generated_at": "<Y-m-d\TH:i:s.v\Z>", "fallback_url"}`. Порожній `fallback_url` → виняток, нічого не пишеться.
- Запис однією транзакцією Redis (`MULTI`/`EXEC` на з'єднанні `spread`): `DEL spread:config:campaigns:tmp` → `HSET spread:config:campaigns:tmp <усі поля>` → `RENAME spread:config:campaigns:tmp spread:config:campaigns` → `SET spread:config:meta` → `EXEC`. Побудова tmp усередині транзакції, щоб дві одночасні перебудови не змішували поля в tmp; це уточнено в контракті цим change.
- Кампаній немає → у транзакції `DEL spread:config:campaigns` + `SET spread:config:meta` (`HSET` без полів і `RENAME` відсутнього ключа — помилки Redis). Для редіректу порожній hash і відсутній ключ однакові (`HGET` → nil); уточнено в контракті.
- Тригери: observer з `ShouldHandleEventsAfterCommit`, підключений через `#[ObservedBy]` до `Campaign` і `Offer`, на `saved` і `deleted`. Після відкату транзакції подія не спрацьовує. Масових `update()`/`delete()` через query builder у коді немає; `DeleteBulkAction` видаляє записи поштучно, тож кожне видалення запускає перебудову (знімок малий).
- Помилка Redis у observer не перехоплюється: адмін бачить помилку, зміна в БД лишається, виправлення — `spread:config:rebuild`. Мовчки застарілий знімок гірший: кліки йшли б на старий URL оффера.
- Команда `spread:config:rebuild` викликає той самий клас і виводить кількість кампаній; при помилці — ненульовий код.

### D4. Маршрут гарячого шляху без стеку `web`
Файл `routes/hot-path.php` з одним маршрутом `GET /c/{alias}` без обмеження формату alias (невідомий alias має дати fallback, а не 404). Реєстрація в `bootstrap/app.php` через `withRouting(then: ...)` без групи `web`: немає сесії, cookies, CSRF. Глобальні middleware Laravel лишаються; `preventRequestsDuringMaintenance(except: ['c/*'])`, щоб `artisan down` не зупиняв редірект. Laravel сам додає `HEAD` до `GET`-маршруту; як його обробляти — D6.

### D5. Модуль `App\HotPath`
- Файли лише в `app/HotPath/` (наприклад, контролер редіректу, розбір параметрів, побудова URL оффера). Дозволено: `Illuminate\Http\*`, `Illuminate\Routing\*`, фасад `Redis` з'єднання `spread`, `config()`, `Symfony\Component\Uid\Ulid` / `Str::ulid()`, `Symfony\Component\HttpFoundation\*`.
- Тест-охоронець (`tests/Unit/HotPathIsolationTest.php`) проходить усі `*.php` у `app/HotPath/` і `routes/hot-path.php` і падає, якщо знаходить: `App\` поза `App\HotPath\`, `Illuminate\Database\`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Schema`, `Eloquent`. У повідомленні — файл і рядок. Тест також перевіряє сам себе на рядку-прикладі з `use App\Models\Campaign;`, щоб регулярний вираз не став «завжди зеленим».

### D6. Послідовність обробки запиту
1. Один pipeline до Redis: `GET spread:config:meta` + `HGET spread:config:campaigns {alias}`.
2. Будь-який виняток Redis або порожній meta → 302 на `config('spread.fallback_url')`. Якщо й він порожній — 302 на `/` (адмін не налаштував середовище; `spread:config:rebuild` без нього не працює, тож на VPS цього не станеться).
3. Немає поля → 302 на `fallback_url` з meta.
4. Є поле, метод `HEAD` → click_id не потрібен для запису, але `Location` має бути тим самим, що в `GET`: генеруємо ULID так само, будуємо URL, `XADD` пропускаємо. Перевірки посилань і модерація рекламних мереж шлють HEAD — це не відвідувачі.
5. Є поле, метод `GET` → `Ulid` → `click_id` і `ts` з того самого ULID (`getDateTime()`, формат `Y-m-d\TH:i:s.v\Z`), тож час ULID точно дорівнює `ts`. `XADD spread:clicks * payload <json>` без `MAXLEN`. Виняток на `XADD` → як у кроці 2 (контракт: Redis недоступний → клік втрачено, fallback з власного конфігу).
6. 302 на URL оффера (`active`) або `fallback_url` з meta (`!active`).

Відповідь — `RedirectResponse` з `Cache-Control: no-store`. Symfony дописує `private` до директив без `public`/`private`, тож фактичний заголовок буде `no-store, private`; директива `no-store` на місці, тест перевіряє саме її.

JSON повідомлення — `json_encode` з `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`. `params` кодується як об'єкт навіть порожнім (`{}`, а не `[]`, — PHP інакше видав би масив).

### D7. Розбір параметрів із сирого рядка запиту
`$request->query()` не годиться: PHP перетворює `a.b` на `a_b`, `sub[1]` на масив, а глобальні middleware `TrimStrings`/`ConvertEmptyStringsToNull` змінюють значення. Тому розбираємо `$request->server('QUERY_STRING')` вручну: `explode('&')`, перший `=`, `urldecode` (так `+` стає пробілом, як у `application/x-www-form-urlencoded` і в Go `url.ParseQuery`). Некоректний UTF-8 → `mb_scrub` (U+FFFD; `json.Marshal` у Go робить те саме). Далі ліміти: ім'я обрізається до 64 символів (`mb_substr`), порожнє ім'я пропускається; нове ім'я додається, лише поки їх менше 50; наявне ім'я перезаписується (останнє значення); значення — до 1024 символів. `user_agent` до 1024, `referer` до 2048 символів, так само через `mb_scrub` + `mb_substr`. «Символ» контракту — символ Unicode (уточнено в контракті).

### D8. Підстановка в `offer_url`
`preg_replace_callback('/\{([A-Za-z0-9_]{1,64})\}/', ...)`: `click_id` → click_id кліку, інакше `rawurlencode(params[name] ?? '')` (RFC 3986, пробіл → `%20`). Регістр важливий. Решта тексту, зокрема дужки з іншим вмістом, лишається як є. Шаблон імені — той самий, що й для назв параметрів джерела (spec traffic-sources), тож плейсхолдер, який можна налаштувати в адмінці, завжди підставляється. Беруться значення після лімітів — ті самі, що в повідомленні.

### D9. IP відвідувача
Редірект читає лише заголовок `X-Real-IP`; немає — `ip: ""`. nginx виставляє його сам (задача 1.1): `fastcgi_param HTTP_X_REAL_IP $remote_addr;` у `location ~ \.php$` файлу `laradock/nginx/sites/85_spread.conf`. Коли `fastcgi_param` задає параметр `HTTP_*`, nginx не передає однойменний заголовок клієнта, тож підробити IP не вийде. `TrustProxies` не налаштовуємо: `$request->ip()` гарячий шлях не використовує.

### D10. Таблиця `clicks`
| Колонка | Тип | Примітка |
|---|---|---|
| `click_id` | `char(26)` PK (`$table->ulid()`) | |
| `clicked_at` | `timestamp(3)` | `ts` з повідомлення, UTC |
| `status` | `varchar(32)` | `ok` / `campaign_disabled` |
| `campaign_id`, `offer_id` | `bigint`, без FK | див. нижче |
| `ip` | `inet` nullable | некоректний/порожній → null |
| `user_agent`, `referer` | `text` nullable | |
| `params` | `jsonb` | як прийшли |
| `country_code` | `char(2)` nullable | |
| `device_type`, `os_name`, `os_version`, `browser_name`, `browser_version` | `varchar` nullable | |
| `is_bot`, `is_duplicate` | `boolean` default false | |
| `created_at` | `timestamp` | час запису; `updated_at` немає |

Індекси: `(clicked_at)` — сортування списку; `(campaign_id, clicked_at)` — фільтр за кампанією; `(ip, clicked_at)` — пошук дублів.

- **`char(26)`, а не `uuid`.** `uuid` економить ~10 байт на рядок, але click_id — це рядок, який CPA-мережа поверне в постбеку і який видно в адмінці й логах; з `uuid` кожне місце (воркер, постбеки етапу 3, Go) мусило б конвертувати ULID ↔ UUID. `char(26)` — нативний формат Laravel `ulid()`, пошук по постбеку — простий `=`. Для одного VPS різниця в розмірі неважлива.
- **Без FK на `campaign_id`/`offer_id`.** Клік потрапляє в БД із затримкою після редіректу. Якщо кампанію видалили, поки її клік ще в стрімі, `INSERT` з FK падав би на кожній спробі й блокував пачку — порушення «жоден клік не губиться». Контракт також прямо каже, що клік пам'ятає `campaign_id`/`offer_id` на момент кліку, навіть якщо кампанію потім змінили. Цілісність зберігаємо на рівні адмінки: кампанію з кліками не можна видалити (spec campaigns, D14) — тими самими `DeleteAction::before()` і `DeleteBulkAction::using()` + `reportBulkProcessingFailure()`, що й у 01-skeleton, але без DB-рівня `restrictOnDelete` (відступ від шаблону CLAUDE.md, свідомий, з наведеної причини). Оффер і так не видаляється, поки в нього є кампанії.
- Партиціонування — етап 7.

### D11. Воркер `spread:clicks:consume`
- Код у `App\Clicks\` (Laravel-частина; запис через query builder, без Eloquent для швидкості); команда — тонка обгортка з циклом, логіка однієї ітерації в окремому класі, щоб тести викликали її напряму.
- Старт: `XGROUP CREATE spread:clicks db 0 MKSTREAM`, `BUSYGROUP` ігнорується. Ім'я consumer: `<hostname>-<pid>`.
- Ітерація: спершу `XAUTOCLAIM spread:clicks db <consumer> 60000 <cursor> COUNT 500` (курсор зберігається між ітераціями); якщо повернуло повідомлення — обробляємо їх, інакше `XREADGROUP GROUP db <consumer> COUNT 500 BLOCK 2000 STREAMS spread:clicks >`. Id, які `XAUTOCLAIM` повертає як видалені, лише `XACK`.
- Пачка: розбір і валідація кожного повідомлення (D12) → збагачення (D13) → `INSERT ... ON CONFLICT (click_id) DO NOTHING` однією командою (`insertOrIgnoreReturning($rows, ['click_id'], ['click_id'])` компілюється саме в цей SQL) → позначка дублів (D13) → `XACK` → `XDEL` для валідних і dead-повідомлень разом. Невалідні спершу `XADD spread:clicks:dead * payload <сире> reason <причина>`.
- Помилка БД або Redis: лог, пауза 5 с, продовження циклу без `XACK`. Повідомлення лишаються в pending і повертаються через `XAUTOCLAIM` після 60 с простою.
- Зупинка: `$this->trap([SIGTERM, SIGINT], ...)` ставить прапорець, цикл перевіряє його між ітераціями; `BLOCK 2000` обмежує час реакції ~2 с.
- Розмір пачки 500 і `BLOCK` 2000 мс — константи в класі, не в конфігу.

### D12. Валідація повідомлення v1
Обов'язкові поля й типи за контрактом: `v` = 1 (int), `click_id` — рядок `^[0-9A-HJKMNP-TV-Z]{26}$`, `ts` — рядок, який розбирається форматом `Y-m-d\TH:i:s.v\Z`, `status` ∈ {`ok`, `campaign_disabled`}, `campaign_id`/`offer_id` — int, `ip` — рядок, `user_agent`/`referer` — рядок або null, `params` — JSON-об'єкт зі значеннями-рядками. Незнайомі поля ігноруються (правило версіонування). Повідомлення без обов'язкового поля не можна записати, а без dead letter воно блокувало б пачку назавжди, тому воно теж іде в dead з причиною; це правило (разом із записом без поля `payload`) уточнено в контракті. JSON розбирається з об'єктами як `stdClass`, щоб відрізнити `{}` від `[]`.

### D13. Збагачення
- **Гео:** `maxmind-db/reader` (підтримуваний MaxMind читач формату mmdb, чистий PHP, без розширення) відкриває `config('spread.geoip_path')` один раз на старті воркера; `country.iso_code` з результату, `ZZ` чи відсутній запис → null. Файлу немає → попередження в лог один раз, країна null. Після `spread:geoip:update` воркер треба перезапустити (файл читається на старті). `geoip2/geoip2` відкинуто: тягне зайве для однієї лише країни.
- **`spread:geoip:update`:** URL `https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.mmdb.gz` за поточний місяць UTC, при 404 — за попередній. Завантаження через HTTP-клієнт Laravel у тимчасовий файл поруч, `gzdecode`, перевірка, що `Reader` відкриває результат, потім `rename` на місце — атомарно, старий файл цілий при будь-якій помилці. Атрибуція в `README.md`: `IP Geolocation by DB-IP (https://db-ip.com), CC BY 4.0`.
- **Пристрій/ОС/браузер/боти:** `matomo/device-detector`. `device_type` = `getDeviceName()`, ОС — `getOs('name'|'version')`, браузер — `getClient('name'|'version')`; порожні значення й `UNK` → null. `is_bot` = `isBot()` або порожній/відсутній User-Agent. Результати кешуються в масиві в межах пачки за рядком UA (у навантажувальному тесті всі UA однакові).
- **Дублі:** після `INSERT` пачки один `UPDATE`:
  `UPDATE clicks c SET is_duplicate = true WHERE NOT c.is_duplicate AND c.ip IN (<ip пачки>) AND c.clicked_at BETWEEN <min ts пачки> AND <max ts пачки> + 60 s AND EXISTS (SELECT 1 FROM clicks p WHERE p.ip = c.ip AND p.user_agent IS NOT DISTINCT FROM c.user_agent AND p.click_id < c.click_id AND p.clicked_at >= c.clicked_at - interval '60 seconds')`.
  Оскільки ULID сортується за часом, `p.click_id < c.click_id` означає «раніший клік». Діапазон `+ 60 s` вгору дозволяє позначити пізніший клік, який уже в БД, коли раніший дійшов із запізненням (після `XAUTOCLAIM`), — тому результат не залежить від порядку обробки. Вікно — константа `DUPLICATE_WINDOW_SECONDS = 60` (підтверджено користувачем). Кліки без IP не позначаються. Вікно ковзне: серія кліків кожні 50 с позначає всі, крім першого.

### D14. Адмінка
- Модель `App\Models\Click`: `$primaryKey = 'click_id'`, `$incrementing = false`, `$keyType = 'string'`, `const UPDATED_AT = null`, casts (`params` → array, `clicked_at` → datetime, прапорці → bool), `belongsTo(Campaign)`. У `Campaign` — `hasMany(Click)` для перевірки видалення.
- Ресурс `App\Filament\Resources\Clicks\` за структурою 01-skeleton: сторінки лише `index` і `view`, `canCreate()` → false, у таблиці лише `ViewAction`, без bulk-дій. Колонки за spec clicks-admin (зокрема `status`, щоб кліки вимкнених кампаній відрізнялися від звичайних), `defaultSort('clicked_at', 'desc')`, `SelectFilter` за `campaign` через relationship. Infolist — усі поля, `params` через `KeyValueEntry`.
- Блокування видалення кампанії з кліками — у `EditCampaign` (`DeleteAction::before()`) і `CampaignsTable` (`DeleteBulkAction::using()` + `reportBulkProcessingFailure()`), за зразком `EditOffer`/`OffersTable`.

### D15. Навантажувальний тест
`hey` (один статичний бінарник, `-disable-redirects`, `-host`) у `~/.local/bin` — встановлення лише з дозволу користувача. Альтернативи: `sudo apt install apache2-utils` (`ab`, без обмеження RPS) або образ Docker із `hey`. Запуск з WSL на `http://localhost/c/<alias>` із `-host spread.local`. `-c 50 -q 6` дає ~300 RPS. Усі кліки з одного IP з тим самим UA, тож майже всі позначаться як дублі, а UA `hey/0.0.1` може розпізнатися як бот — це очікувано, тест рахує лише кількість.

## Risks / Trade-offs

- [Laravel піднімає весь фреймворк на кожен клік; при ~300 RPS пул php-fpm-85 (спільний з іншим сайтом, `pm.max_children` 5–20) може стати вузьким місцем] → Тест фіксує фактичні RPS і латентність. Якщо з'являться помилки 502/504, це обмеження локального середовища; кліки, що отримали 302, усе одно мають бути в БД. Оптимізація (config/route cache, пул FPM) — окремим рішенням, не в цьому change.
- [Redis недоступний → кліки втрачаються] → Свідомо прийнято контрактом для Laravel-версії; файл-фолбек — етап 8.
- [Помилка Redis під час збереження кампанії в адмінці дає помилку при вже збереженій у БД зміні] → Рідкісний випадок; `spread:config:rebuild` виправляє. Краще за мовчки застарілий знімок.
- [Воркер не під наглядом: упав — кліки накопичуються в стрімі] → Нічого не губиться, стрім не обрізається; після перезапуску все дообробиться. Нагляд — етап 7, алерт за `XLEN` — етап 6.
- [Без FK можливі кліки з `campaign_id` видаленої кампанії, якщо видалити кампанію, поки її перший клік ще в стрімі] → Кліки не губляться; у списку така кампанія без назви. Вікно — секунди, користувач один.
- [Тести тепер залежать від Redis: створення кампанії в будь-якому тесті перебудовує знімок] → Redis доступний у workspace; тести пишуть у DB 15.
- [Файл GeoIP застаріває] → Оновлення командою вручну; планувальник — етап 7.
- [`matomo/device-detector` чи `maxmind-db/reader` можуть не мати версії, сумісної з PHP 8.5] → Задача починається з `composer require`; немає сумісної версії — зупинитися й спитати користувача.

## Migration Plan

Порядок: 1.1 (nginx, можна одразу) → 2.x (з'єднання, знімок) → 3.x (редірект і охоронець) → 4.x (таблиця, воркер, збагачення) → 5.x (адмінка) → 6.x (навантажувальний тест, після дозволу на встановлення інструмента) → 7.x (ручна перевірка). Після merge: `php artisan migrate`, `php artisan spread:config:rebuild`, `php artisan spread:geoip:update`, запустити воркер. Відкат: прибрати маршрут із `bootstrap/app.php` (посилання знову 404), `migrate:rollback` таблиці `clicks`; ключі `spread:*` можна видалити без наслідків для решти.

## Уточнення контракту

Контракт v1 мовчав у кількох місцях, важливих для паритету з Go (етап 8). Користувач затвердив варіанти 2026-09-24, і цей change вносить їх у docs/contract.md як уточнення v1: формат повідомлень і знімка не змінюється, `v` лишається 1.

| Уточнення | Розділ контракту | Рішення тут |
|---|---|---|
| Побудова tmp усередині `MULTI`; нуль кампаній → `DEL` hash, пишеться лише meta | «Як Laravel оновлює знімок» | D3 |
| `HEAD` → той самий 302 і `Location`, клік не пишеться | «Редірект» | D6 |
| Плейсхолдер `{[A-Za-z0-9_]{1,64}}`, інші `{...}` як є; значення кодуються за RFC 3986 (`%20`); `{click_id}` має пріоритет | «Підстановка в `offer_url`» | D8 |
| Розбір рядка запиту: імена як є, `+` — пробіл, порожні імена пропускаються, останнє значення перемагає | «Розбір query-параметрів» (новий підрозділ) | D7 |
| Ліміти в символах Unicode після заміни некоректного UTF-8 на U+FFFD; 50 різних імен у порядку першої появи | «Ліміти» | D7 |
| `X-Real-IP` виставляє nginx; немає заголовка → `ip: ""` | «Повідомлення про клік», поле `ip` | D9 |
| Без `payload` або з невалідними полями відомої `v` → `spread:clicks:dead` | «Воркер кліків» | D12 |

## Підтверджені рішення

Користувач 2026-09-24 підтвердив: вікно дублів 60 с за IP + User-Agent без урахування кампанії (D13); порожній User-Agent = бот (D13); без FK на `clicks.campaign_id`, видалення кампанії з кліками блокується лише в адмінці — свідомий відступ від шаблону delete-blocking (D10); колонка `status` у списку кліків (D14); 302 на `/`, якщо не задано навіть `SPREAD_FALLBACK_URL` (D6); одне Redis-з'єднання `spread` (D1); розбір сирого `QUERY_STRING` (D7); `char(26)` для `click_id` (D10).
