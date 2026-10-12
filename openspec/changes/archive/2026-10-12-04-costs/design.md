# Design: 04-costs

## Context

Після 03-postbacks є кампанії, кліки (`clicked_at` в UTC), конверсії з `payout` numeric(12,4) і `currency` char(3) як прийшли з постбеку, CPA-мережі. Налаштувань у БД немає: усе в `config/spread.php` і `.env`. Планувальник не налаштований (`routes/console.php` містить лише `inspire`), на локальному Laradock cron не запущений. Блокування видалення кампанії з кліками — у `EditCampaign` (`DeleteAction::before()`) і `CampaignsTable` (`DeleteBulkAction::using()`). Таблиці мають PostgreSQL 18, тож доступний `NULLS NOT DISTINCT`.

API НБУ перевірено 2026-10-12: `GET https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?date=YYYYMMDD&json` повертає масив `{r030, txt, rate, cc, exchangedate}` для ~45 валют, `rate` — гривень за 1 одиницю (`USD` 44.8526, `JPY` 0.28341, `XAU` 185061.47), дати є й на вихідні, і на завтра.

Мотивація й рішення користувача — proposal.md, вимоги — specs/.

## Goals / Non-Goals

**Goals:**
- Звіти Етапу 5 складають уже готові суми в базовій валюті (`amount_base`, `payout_base`), без переведення валют у запитах.
- Оригінальні суми й валюти ніколи не змінюються; суми в базовій валюті можна перерахувати будь-коли.

**Non-Goals:**
- Групування кліків і конверсій за днями в таймзоні звітів (Етап 5).
- Курси з інших джерел, крім НБУ й ручних.
- Черги для перерахунку: обсяги одного користувача дозволяють синхронний перерахунок.

## Decisions

### D1. Налаштування
- Таблиця `settings` з одним рядком: `id`, `base_currency` char(3), `report_timezone` string(64), `timestamps`. Модель `App\Models\Setting` з `Setting::current(): Setting` — повертає наявний рядок або незбережений екземпляр із значеннями за замовчуванням (`USD`, `Europe/Kyiv`), тож код ніколи не бачить «порожніх» налаштувань.
- Альтернатива — key/value таблиця або пакет `spatie/laravel-settings`: відкинуто, бо полів два і вони типізовані; один рядок з колонками простіший.
- Filament: власна сторінка `App\Filament\Pages\Settings` із формою (`TextInput` валюти з regex `^[A-Za-z]{3}$` і перетворенням у верхній регістр, `Select` таймзони з `DateTimeZone::listIdentifiers()` з пошуком) і дією збереження. Якщо базова валюта змінилась — після збереження синхронно викликається перерахунок усіх сум (D6), адмін бачить повідомлення з кількістю перерахованих.

### D2. Курси
- Таблиця `exchange_rates`: `id`, `date` date, `currency` char(3), `uah_per_unit` numeric(18,8), `source` string(8) (`nbu`/`manual`), `timestamps`; unique (`currency`, `date`).
- `App\Money\NbuClient::fetch(CarbonImmutable $date): array<string, string>` — `Http::timeout(10)->get(...)`, перевірка статусу й форми JSON, повертає `cc => rate` рядками. У JSON `rate` — float, але курси НБУ мають не більше 5 знаків після коми, тож `sprintf('%.8F', $rate)` передає їх у numeric(18,8) точно. Помилка → виняток `NbuUnavailable`.
- Команда `spread:rates:update {--from=} {--to=}`: без опцій — сьогодні й завтра (UTC). Для кожної дати: `fetch`, далі `upsert` лише рядків, де немає `manual` (запит `insert ... on conflict (currency, date) do update ... where exchange_rates.source <> 'manual'`). Порожня відповідь — пропуск дати. Будь-яка помилка → повідомлення й ненульовий код, уже записані дати лишаються (кожна дата — окрема транзакція). Після запису — перерахунок сум без базової суми (D6).
- Розклад: `Schedule::command('spread:rates:update')->dailyAt('09:00')->timezone('Europe/Kyiv')` у `routes/console.php` (НБУ публікує курс на завтра вдень). Запуск планувальника (cron/systemd) — Етап 7.
- Filament-ресурс `ExchangeRates`: список (дата desc, валюта, курс, джерело; фільтри валюти й періоду), створення й редагування (джерело завжди стає `manual`), без видалення — помилковий курс виправляється редагуванням.

### D3. Переведення валют
`App\Money\CurrencyConverter`:
- `toBase(string $amount, string $currency, CarbonImmutable $date): ?string` — арифметика в `bcmath` (`bcmul`/`bcdiv` зі шкалою 12, далі округлення half-up до 4 знаків), без float. Розширення `bcmath` є в workspace-85 і php-fpm-85 (перевірити в задачі 1.1; немає — зупинитися й спитати).
- Курс валюти на дату: `exchange_rates where currency = ? and date <= ? and date >= ? - 7 days order by date desc limit 1`; `UAH` → `1`. Кеш у межах одного виклику перерахунку (масив `currency|date`), щоб не робити запит на кожен рядок.
- Базова валюта береться з `Setting::current()` в момент обчислення й зберігається поруч із сумою (`base_currency`), тож видно, для якої валюти суму пораховано.

### D4. Витрати
- Таблиця `costs`: `id`, `campaign_id` FK `restrictOnDelete`, `date` date, `zone` string(255) nullable, `amount` numeric(14,4), `currency` char(3), `amount_base` numeric(14,4) nullable, `base_currency` char(3) nullable, `timestamps`; unique (`campaign_id`, `date`, `zone`) `NULLS NOT DISTINCT` (сирий SQL у міграції); індекс (`date`, `campaign_id`).
- Правило «або кампанія, або зони» перевіряє `App\Money\CostRules` (і форма, і імпорт): для `zone = null` не має бути рядків цієї кампанії й дати із зоною, і навпаки. На рівні БД не виражається — свідомо лише в застосунку.
- `amount_base` обчислюється в `Cost::saving` через `CurrencyConverter` за `date`.
- Filament-ресурс `Costs`: форма (кампанія — `Select` з пошуком, дата, зона, сума — `TextInput` з regex `^\d{1,10}(\.\d{1,4})?$`, валюта — за замовчуванням базова), список з колонками й фільтрами зі spec, `amount_base` з позначкою `no rate` для null. Видалення дозволене: витрати — дані користувача.

### D5. Імпорт CSV
- Дія `ImportCostsAction` у заголовку списку витрат: модальне вікно з `FileUpload` (`.csv`, до 2 МБ, тимчасовий диск `local`) і описом формату.
- `App\Money\CostCsvImporter::import(string $path): CostImportResult` — читає `SplFileObject` + `fgetcsv`, прибирає UTF-8 BOM, перевіряє заголовок (порядок колонок довільний, бракує обов'язкових → помилка з їх переліком), кожен рядок (alias → кампанія одним запитом на всі alias файлу; дата `Y-m-d` з точним зворотним форматуванням; сума regex D4; валюта `^[A-Za-z]{3}$`), дублі ключа в межах файлу й конфлікти «кампанія/зони» з файлом і з БД. Помилки збираються всі (до 50 у повідомленні), нічого не пишеться. Без помилок — одна транзакція: для кожного рядка `updateOrCreate` за ключем (щоб спрацював `saving` і порахувалась `amount_base`), лічильники created/updated.
- Альтернатива — вбудований Filament Importer: відкинуто, бо він працює через черги й таблицю `imports`, обробляє рядки по одному й не дає «все або нічого».

### D6. Перерахунок сум
`App\Money\Recalculator`:
- `missing()` — рядки `costs` і `conversions` (з `payout`) без суми в базовій валюті або з `base_currency` ≠ поточної;
- `all()` — усі такі рядки;
- `forRate(string $currency, CarbonImmutable $date)` — рядки у цій валюті з датами від `$date` до `$date + 7 днів` (курс міг бути вибраний як «найближчий попередній»); якщо `$currency` — базова, то всі рядки в цьому вікні дат.
Кожен метод оновлює пачками по 500 і повертає кількість. Виклики: команда `spread:money:recalculate {--all}`, після `spread:rates:update` (`missing`), після створення/редагування курсу в адмінці (`forRate`), після зміни базової валюти (`all`).

### D7. Виплати конверсій
- Нові колонки `conversions`: `payout_base` numeric(14,4) nullable, `base_currency` char(3) nullable. Нова колонка `cpa_networks.default_currency` char(3) not null default `'USD'`.
- `Conversion::saving`: якщо `payout` null → `payout_base` і `base_currency` null; інакше валюта = `currency ?? cpaNetwork->default_currency`, дата = `status_changed_at` у таймзоні звітів (`->setTimezone(Setting::current()->report_timezone)->toDateString()`), далі `CurrencyConverter`. Так `PostbackProcessor` не змінюється: він і так зберігає модель.
- Для `Recalculator` дата конверсії обчислюється тим самим методом моделі `Conversion::rateDate()`, щоб правило було в одному місці.

### D8. Видалення кампанії з витратами
`costs.campaign_id` `restrictOnDelete` + друга перевірка в `EditCampaign` (`before()`) і `CampaignsTable` (`using()` + `reportBulkProcessingFailure('has_costs', ...)`) поруч із наявною перевіркою кліків — шаблон delete-blocking з CLAUDE.md.

### D9. Тести
`Http::fake()` для НБУ (успіх, 500, невалідний JSON, порожній масив); `Setting` через фабричні значення в тестах; CSV-файли — тимчасові файли в тесті. Тести перерахунку перевіряють числа зі spec (`111.7850`, `22.2953`).

## Risks / Trade-offs

- [Планувальник локально не запущений — курси самі не оновлюються] → Команду запускають вручну; на VPS cron — Етап 7. Суми без курсу видно як «no rate», перерахунок спрацює після завантаження.
- [API НБУ змінить формат або стане недоступним] → Ненульовий код і незмінені курси; ручне введення як запасний шлях.
- [Курс «найближчий попередній за 7 днів» може бути неточним для свят] → НБУ публікує курси на кожен календарний день, тож вікно потрібне лише на випадок пропущеного завантаження.
- [Синхронний перерахунок усіх сум при зміні базової валюти може бути повільним на великих обсягах] → Для одного користувача — тисячі рядків, секунди; якщо стане повільно — винести в чергу.
- [Правило «кампанія або зони» не гарантоване на рівні БД] → Усі шляхи запису (форма, імпорт) проходять через `CostRules`; прямих записів немає.
- [`float` у JSON-відповіді НБУ] → Курси НБУ мають до 5 знаків; `sprintf('%.8F')` від float точно відтворює такі значення.

## Migration Plan

Порядок: 1.x (налаштування, курси, переведення) → 2.x (витрати, імпорт, кампанії) → 3.x (виплати конверсій, перерахунок) → 4.x (ручна перевірка). Після merge: `php artisan migrate` (наявні мережі отримують `USD`), `php artisan spread:rates:update --from=<дата першого кліку>`, `php artisan spread:money:recalculate`. Відкат: `migrate:rollback` нових таблиць і колонок; гарячий шлях і обробка постбеків від цього не залежать.

## Підтверджені рішення

Користувач 2026-10-12 підтвердив: базова валюта USD; курси НБУ автоматично щодня плюс ручне введення й виправлення; свій шаблон CSV `date,campaign,zone,amount,currency`; базова валюта й таймзона на сторінці налаштувань в адмінці, таймзона за замовчуванням Europe/Kyiv.

Прийнято в design без окремого підтвердження, на перегляд користувачем: дата витрати — календарний день у таймзоні звітів (D4); «або витрата всієї кампанії, або по зонах» за день (D4); CSV «все або нічого», повторний імпорт замінює суми (D5); курс «на дату або найближчий попередній за 7 днів» (D3); округлення до 4 знаків half-up (D3); валюта мережі за замовчуванням USD для постбеків без валюти (D7); дата переведення виплати — день останньої зміни статусу в таймзоні звітів (D7); ручний курс не перезаписується НБУ й не видаляється, лише редагується (D2).
