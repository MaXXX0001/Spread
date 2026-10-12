# Design: 03-postbacks

## Context

Після 02-redirect є ізольований гарячий шлях `App\HotPath` (`RedirectController`, `QueryParams`, `OfferUrl`, `Utf8`), маршрут у `routes/hot-path.php` поза стеком `web`, виняток `c/*` для режиму обслуговування, Redis-з'єднання `spread` без префікса, воркер кліків `App\Clicks\ClickConsumer` + команда `spread:clicks:consume` (consumer group `db`, `XAUTOCLAIM`, dead letter) і тест-охоронець `HotPathIsolationTest`, який сканує всі файли `app/HotPath/` і `routes/hot-path.php`. `cpa_networks` має лише `name` і `notes`, у ресурсі немає сторінки перегляду. `clicks.click_id` — `ulid` (char(26)), FK на кампанію немає. Base URL трекера — `config('spread.tracker_url')`.

Мотивація — proposal.md, вимоги — specs/. Рішення користувача 2026-10-12: один наш формат постбеку з маппінгом статусів для кожної мережі; одна конверсія на клік; ключ перевіряє воркер, приймач завжди відповідає `200`.

## Goals / Non-Goals

**Goals:**
- Приймач постбеку переноситься на Go без змін у Laravel: він знає лише контракт, а не мережі, оффери чи конверсії.
- Жоден постбек, що потрапив у стрім, не губиться: він або в сирому лозі з результатом, або в dead letter.
- Баг в обробці не губить постбек і не блокує стрім: запис логу стає `failed` і переграється командою.

**Non-Goals:**
- Підпис HMAC, `POST`-постбеки, назви параметрів для кожної мережі, кілька конверсій на клік.
- Конвертація валют, агрегати й звіти (етапи 4–5), алерти (етап 6), rate limiting (етап 7).
- Перевірка, що оффер кліку належить мережі постбеку: конверсія захищена ключем мережі й `network_mismatch`; ширша звірка — за потреби пізніше.

## Decisions

### D1. Маршрут і контролер приймача
- `routes/hot-path.php`: `Route::get('/p/{key}', PostbackController::class)` без обмеження формату `key`. `bootstrap/app.php`: `preventRequestsDuringMaintenance(except: ['c/*', 'p/*'])`.
- `App\HotPath\PostbackController` за зразком `RedirectController`: `HEAD` → одразу `200 OK`; `GET` → ULID, `ts` з `getDateTime()` у форматі `Y-m-d\TH:i:s.v\Z`, `key` = `Utf8::truncate($key, 64)`, `ip` = `X-Real-IP` або `""`, `params` = `QueryParams::parse(QUERY_STRING)` як об'єкт, `XADD spread:postbacks * payload <json>` без `MAXLEN`. `RedisException` або `false` від `XADD` → `503`. Відповідь — `Response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store'])`.
- Ключ у приймачі не перевіряється (рішення користувача): інакше ключі мереж довелося б класти в знімок конфігу, і Go-сервіс мав би знати про мережі. Ціна — на постбек із чужим ключем теж відповідаємо `200`.
- `503`, а не `200`, коли Redis недоступний: `200` сказав би мережі «доставлено», і конверсія загубилась би назавжди; на не-2xx частина мереж повторює спробу. Для кліку контракт приймає втрату, для постбеку — ні, бо постбек не повториться сам.
- Альтернатива: приймати `GET` і `POST` — відкладено, поки обрана мережа цього не вимагатиме.

### D2. Повідомлення v1 і доповнення контракту
У docs/contract.md додається розділ «Постбеки» тим самим комітом, що й контролер (задача 3.1). Розділи про кліки й знімок не змінюються. Зміст:
- Потік: `мережа → GET /p/{key}?… → XADD spread:postbacks → 200`; воркер `XREADGROUP` → сирий лог → конверсія → `XACK` + `XDEL`.
- Ключі Redis: `spread:postbacks` (Stream, поле `payload`, пише приймач, читає воркер), `spread:postbacks:dead` (Stream, поля `payload`, `reason`).
- Таблиця відповідей: записано → `200 OK`; Redis недоступний → `503`; `HEAD` → `200` без запису; `Cache-Control: no-store`; ключ і параметри приймач не перевіряє. Go-версія (етап 8) замість `503` писатиме постбек у локальний файл.
- Повідомлення: `{"v": 1, "postback_id": "<ULID>", "ts": "<UTC ms>", "key": "<до 64 символів>", "ip": "<X-Real-IP або \"\">", "params": {…}}`; `params` — за правилами «Розбір query-параметрів» і «Ліміти» розділу редіректу.
- Воркер: група `db` на `spread:postbacks`, «хоча б раз», `XAUTOCLAIM` понад 60 с, невалідні повідомлення → `spread:postbacks:dead`, ідемпотентність за `postback_id`.
- Наш формат параметрів постбеку (`click_id`, `status`, `payout`, `currency`, `txid`) — це домовленість з мережами, а не з Go-сервісом; у контракті він згадується одним рядком із посиланням на spec `postback-processing`.

### D3. Налаштування CPA-мережі
- Міграція додає до `cpa_networks`: `postback_key` char(32) unique, `status_map` jsonb not null default `'{}'`, `allowed_ips` jsonb not null default `'[]'`. Наявним мережам міграція генерує ключі (`random_int` по алфавіту `[a-z0-9]`, перевірка унікальності), потім колонка стає `NOT NULL`.
- Модель `CpaNetwork`: ключ генерується в `creating` тим самим способом, що alias кампанії (`random_int`, цикл до унікального); метод `regeneratePostbackKey()`; `postbackUrl()` = `rtrim(tracker_url, '/') . '/p/' . key . '?click_id=&status=&payout=&currency=&txid='`. Касти `status_map` і `allowed_ips` — `array`.
- `status_map` зберігається як об'єкт `{"<значення мережі, trim + lowercase>": "<наш статус>"}`, тож пошук у воркері — один доступ за ключем. Нормалізація — при збереженні; дублікати після нормалізації — помилка валідації.
- Наші статуси — backed enum `App\Postbacks\ConversionStatus` (`LEAD = 'lead'`, `HOLD`, `APPROVED`, `REJECTED`) з методом `canTransitionTo()` за таблицею зі spec.
- Filament: у формі — `Repeater` пар (`TextInput` значення мережі + `Select` нашого статусу) з перетворенням у/з об'єкта; `Textarea` для IP (по одному на рядок, порожні рядки відкидаються; кожен рядок перевіряється як IP або CIDR через `IpUtils`/`filter_var`). Нова сторінка `ViewCpaNetwork` з інфолистом: URL постбеку (`copyable`, підказка про макроси), маппінг, IP; дія «Regenerate key» з `requiresConfirmation()` на сторінці перегляду. Ключ у форму не потрапляє.

### D4. Таблиці
`postbacks` (сирий лог):
- `postback_id` ulid PK, `received_at` timestamp(3) (= `ts`), `cpa_network_id` nullable FK `nullOnDelete`, `ip` inet nullable (`""` → null), `params` jsonb, `state` string(16), `reason` text nullable, `click_id` char(26) nullable (валідний `click_id` з параметрів, для пошуку й посилань), `conversion_id` nullable FK, `processed_at` timestamp(3) nullable, `created_at`.
- Індекси: `received_at`, (`state`, `received_at`).

`conversions`:
- `id`, `click_id` char(26) unique, `cpa_network_id` FK `restrictOnDelete`, `campaign_id` і `offer_id` bigint nullable без FK (як у `clicks`; null = сирітська), `status` string(16), `payout` numeric(12,4) nullable, `currency` char(3) nullable, `txid` string(255) nullable, `last_postback_at` timestamp(3), `status_changed_at` timestamp(3), `created_at`, `updated_at`.
- Індекси: `status_changed_at`, (`campaign_id`, `status_changed_at`), частковий `(click_id) WHERE campaign_id IS NULL` (сирий SQL у міграції) для прив'язки сиріт.

`conversion_status_changes`:
- `id`, `conversion_id` FK, `postback_id` FK на `postbacks`, `from_status` nullable, `to_status`, `payout`, `currency`, `changed_at` timestamp(3) (= `received_at` постбеку), `created_at`. Індекс (`conversion_id`, `changed_at`).

Моделі `Postback`, `Conversion`, `ConversionStatusChange` з кастами (`params` array, `status` enum, дати). `Conversion::click()` — `belongsTo(Click::class, 'click_id', 'click_id')`.

### D5. Воркер `spread:postbacks:consume`
`App\Postbacks\PostbackConsumer` за зразком `ClickConsumer` (група `db`, пачка 100, `BLOCK` 2000 мс, `XAUTOCLAIM` 60 с, `iterate()` повертає `false` при помилці БД/Redis), команда — копія `ConsumeClicks` з тим самим `trap` SIGTERM/SIGINT. Одна ітерація:
1. Розбір повідомлень (`PostbackMessageParser`, як `ClickMessageParser`): невалідні → `spread:postbacks:dead`.
2. Ключі пачки → мережі одним запитом `whereIn('postback_key', …)`.
3. Вставка всіх валідних у `postbacks` одним `insertOrIgnore` зі станом `received` і `cpa_network_id` (null для невідомого ключа). Помилка → `false`, усе лишається pending.
4. Для кожного повідомлення: читаємо рядок `postbacks`; якщо стан не `received` і не `failed` — уже оброблено при попередній доставці, пропускаємо. Інакше `PostbackProcessor::process($postback)` у `try`; будь-який `Throwable` → рядок стає `failed`, `reason` = клас і текст винятку. Якщо й позначка `failed` падає (БД недоступна) — виняток виходить з ітерації, повідомлення лишаються pending.
5. Dead letter, потім `XACK` + `XDEL` усіх id пачки.
6. Прив'язка сиріт (D7) — на кожній ітерації, навіть з порожньою пачкою.

Так відмова БД лишає повідомлення в стрімі, а баг обробки — у лозі як `failed`, і стрім не зациклюється на «отруйному» повідомленні.

### D6. Обробка одного постбеку
`App\Postbacks\PostbackProcessor::process(Postback $postback): void` — одна транзакція БД, результат записується в той самий рядок `postbacks` (`state`, `reason`, `conversion_id`, `processed_at`). Порядок перевірок:
1. `cpa_network_id` null → `rejected` / `invalid_key`.
2. Whitelist не порожній і `IpUtils::checkIp(ip, allowed_ips)` хибне (або ip null) → `rejected` / `ip_not_allowed`.
3. `click_id` не відповідає `^[0-7][0-9A-HJKMNP-TV-Z]{25}$` після `strtoupper` → `rejected` / `invalid_click_id`.
4. `status` → `trim` + `mb_strtolower` → `status_map[...]` ?? `ConversionStatus::tryFrom(...)`; null → `rejected` / `unknown_status`.
5. `payout`: `^\d{1,8}([.,]\d{1,4})?$`, кома → крапка; `currency`: `^[A-Za-z]{3}$` → upper. Невалідні → null + зауваження в `reason` (`invalid_payout`, `invalid_currency`, через кому), стан лишається `processed`.
6. `Conversion::where('click_id', …)->lockForUpdate()->first()`:
   - немає → створити (кампанія й оффер із `clicks`, якщо клік є), запис історії `null → status`, `processed`;
   - мережа інша → `rejected` / `network_mismatch`;
   - `received_at < last_postback_at` → `ignored` / `stale`;
   - статус той самий → `ignored` / `same_status`;
   - `! canTransitionTo()` → `ignored` / `transition_not_allowed`;
   - інакше оновити статус, `status_changed_at`, `last_postback_at`, виплату/валюту/`txid`, якщо валідні й передані; запис історії; `processed`.

Унікальний індекс `click_id` страхує від дубля, якщо колись буде два воркери: вставка конфліктує, транзакція падає → `failed` → переграти. Для одного воркера цього не станеться.

### D7. Сирітські конверсії
Після кожної ітерації воркер виконує один запит:
`UPDATE conversions c SET campaign_id = k.campaign_id, offer_id = k.offer_id, updated_at = now() FROM clicks k WHERE c.campaign_id IS NULL AND k.click_id = c.click_id`.
Частковий індекс робить його дешевим, поки сиріт мало. Воркер кліків не змінюється — зв'язок лише через БД. Альтернатива (прив'язувати у воркері кліків після вставки пачки) відкинута: вона змішує дві відповідальності й торкається коду етапу 2. Конверсія з `click_id`, якого ніколи не було, лишається сирітською назавжди й видна у фільтрі адмінки.

### D8. Команда `spread:postbacks:replay`
`{--id=*} {--state=*} {--from=} {--to=}`: `--id` — конкретні записи будь-якого стану; інакше стан за замовчуванням `failed` (можна кілька `--state`), `--from`/`--to` — межі `received_at` в UTC. Записи з `cpa_network_id` null пропускаються з лічильником. Обробка по одному в порядку (`received_at`, `postback_id`) тим самим `PostbackProcessor`, з тим самим перехопленням `Throwable` → `failed`. Наприкінці — таблиця «результат → кількість» і кількість пропущених. Правило `stale` гарантує, що переграш старого постбеку не перезапише новіший стан.

### D9. Адмінка
- Виплата в адмінці показується без зайвих нулів після коми разом із валютою (`12.5 USD`, `10 USD`); без валюти — лише число.
- `Conversions`: `ListConversions` (колонки й фільтри зі spec, `campaign.name` або бейдж `orphan`, `TernaryFilter`/`Filter` «Orphans only» за `campaign_id IS NULL`, сортування за `status_changed_at desc`) і `ViewConversion` (поля, посилання на клік, якщо `click` є, `RepeatableEntry` історії з посиланнями на постбек).
- `Postbacks`: `ListPostbacks` (колонки зі spec, `click_id` і `status` — з `params`, фільтри за `state` і мережею, сортування за `received_at desc`) і `ViewPostback` (`KeyValueEntry` для `params`, `reason`, посилання на конверсію).
- Обидва ресурси лише `index` і `view`, як `Clicks`; `canCreate()` false, без дій редагування й видалення.
- Видалення мережі: DB `restrictOnDelete` на `conversions.cpa_network_id` + `DeleteAction::before()` з повідомленням і скасуванням + `DeleteBulkAction::using()` з `reportBulkProcessingFailure()` — шаблон delete-blocking з CLAUDE.md, поруч із наявною перевіркою офферів.

### D10. Тести
Як у 02-redirect: Redis-тести на DB 15 (`FLUSHDB` лише на з'єднанні `spread`), тестова БД `spread_testing`. Тест ізоляції покриває новий контролер без змін — він сканує всю теку `app/HotPath/`. Логіка обробки тестується викликом `PostbackProcessor` і `PostbackConsumer::iterate()` напряму.

## Risks / Trade-offs

- [На постбек із чужим ключем відповідаємо `200`, і зловмисник бачить «успіх»] → Підробка однаково потребує валідного ключа й реального `click_id`; відхилені постбеки видно в лозі; rate limiting — етап 7.
- [Ключ мережі в URL потрапляє в access-логи проксі й nginx] → У нашій БД ключа немає; на VPS вимкнути або маскувати access-лог для `/p/` (етап 7); при витоку — перегенерувати ключ.
- [Мережа не повторює постбек після `503`] → Залежить від мережі; Redis на тому самому сервері падає рідко; файл-фолбек — етап 8.
- [Таблиця переходів не підійде якійсь мережі (наприклад, `rejected → hold`)] → Такий постбек видно в лозі як `ignored` / `transition_not_allowed`; таблиця — в enum, змінюється однією правкою, а постбеки переграються.
- [Конверсія з помилковим `click_id` назавжди лишається сирітською] → Видно у фільтрі «Orphans only»; на звіти етапу 5 не впливає, бо в неї немає кампанії.
- [Помилка БД посеред обробки (не відмова, а, наприклад, deadlock) позначає постбек як `failed`] → Переграти командою; алерт на `failed` — етап 6.
- [Наш формат параметрів може не підійти обраній мережі] → Перед реалізацією, щойно мережу обрано, звірити її постбек із форматом; налаштовувані назви параметрів можна додати, не змінюючи контракт.

## Migration Plan

Порядок: 2.x (мережі) → 3.1 (приймач + контракт) → 4.x (таблиці, воркер, обробка, сироти, переграш) → 5.x (адмінка) → 6.x (ручна перевірка). Після merge: `php artisan migrate` (ключі наявних мереж генеруються міграцією), запустити `spread:postbacks:consume` поруч із `spread:clicks:consume`. Відкат: прибрати маршрут `/p/{key}` (постбеки дадуть 404, і мережі, що вміють, повторять їх пізніше), `migrate:rollback` трьох таблиць і колонок; ключі `spread:postbacks*` можна видалити.

## Підтверджені рішення

Користувач 2026-10-12 підтвердив: один наш формат постбеку (`/p/{key}?click_id&status&payout&currency&txid`) з маппінгом статусів для кожної мережі (D2, D3); одна конверсія на клік (D6); ключ перевіряє воркер, приймач завжди відповідає `200` (D1); без HMAC для MVP.

Прийнято в design без окремого підтвердження, на перегляд користувачем: `503`, коли Redis недоступний (D1); лише `GET` (D1); ключ 32 символи `[a-z0-9]` і перегенерація (D3); таблиця дозволених переходів (spec `postback-processing`); правила `stale` і `network_mismatch` (D6); маппінг перевіряється раніше за наші назви статусів (D6); невалідні `payout`/`currency` не відхиляють постбек (D6); прив'язка сиріт воркером постбеків (D7); записи з невалідним ключем не переграються (D8).
