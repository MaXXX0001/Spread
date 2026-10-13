# Design: 05-reports

## Context

Після 04-costs є:
- `clicks`: `clicked_at` timestamp(3) в UTC, `campaign_id` без FK, `params` jsonb рівно як прийшли, `country_code`, `device_type`, `os_name`, `is_bot`, `is_duplicate`. Індекси `clicked_at` і (`campaign_id`, `clicked_at`).
- `conversions`: одна на `click_id` (unique), `status`, `payout`, `payout_base` numeric(14,4), `base_currency`. Сирітська має `campaign_id` null.
- `costs`: (`campaign_id`, `date`, `zone` null|string) унікальні з `NULLS NOT DISTINCT`, `amount_base` numeric(14,4) або null, якщо курсу немає. `date` — день у таймзоні звітів.
- `traffic_sources.macros` — jsonb-масив `[{param, macro}]`.
- `App\Money\Recalculator` (`missing()`, `all()`, `forRate()`) повертає `[costs, conversions]` перерахованих. Його викликають `spread:rates:update`, `spread:money:recalculate`, сторінка `Settings`, створення й редагування курсу.
- Планувальник: лише `spread:rates:update`, cron локально не запущений (Етап 7). Черга Redis, Horizon, `supervisor-1` на `default`.
- Filament 5: таблиці приймають власні дані через `Table::records(Closure)`, без Eloquent-запиту.

Мотивація й рішення користувача — proposal.md, вимоги — specs/.

## Goals / Non-Goals

**Goals:**
- Один SQL-запит будує будь-який звіт з агрегату й витрат. Сторінка, CSV, збиткові зони й дашборд користуються ним, тож цифри всюди однакові.
- Агрегат не залежить від таймзони звітів і зберігає лише те, що змінюється заднім числом: кліки й конверсії.

**Non-Goals:**
- Матеріалізація витрат в агрегаті: витрат кілька рядків на день, їх читають напряму.
- Інкрементальний перерахунок «брудних» годин за змінами конверсій. Вистачає двох ритмів і повної перебудови за подією.
- Графіки на дашборді.

## Decisions

### D1. Параметри зони й креативу джерела
- Нові колонки `traffic_sources.zone_param` і `creative_param`, string(64) nullable.
- У формі `TrafficSourceForm` додаються два `Select`, опції яких беруться з поточного стану репітера `macros` (`live()`), тобто список змінюється разом із парами. Валідація: значення є серед `param` поточних пар і поля не рівні між собою. Вбудований `in()` не бачить стану репітера, тому потрібне closure-правило.
- Альтернатива: прапорці «зона»/«креатив» на кожній парі репітера. Її відкинуто, бо тоді «рівно одна зона» перевіряється складніше, а дані jsonb змінили б форму, яку вже читає побудова трекінгового посилання.

### D2. Таблиця `report_stats`
- Колонки: `hour` timestamp(0) (UTC, початок години), `campaign_id` bigint, `zone` text, `creative` text, `country_code` varchar(2), `device_type` varchar(255), `os_name` varchar(255). Усі розрізи NOT NULL, а «немає значення» зберігається як `''`. Так `GROUP BY` і зіставлення з `costs.zone` не мають справи з NULL.
- Лічильники: `clicks`, `unique_clicks`, `bot_clicks`, `conversions`, `approved`, `hold`, `rejected`, `no_rate` (integer), `revenue`, `expected_revenue` numeric(16,4).
- Індекси: (`hour`), (`campaign_id`, `hour`). Унікального ключа немає: рядки годин завжди видаляються й вставляються заново, а не оновлюються. `zone` має тип text, бо значення параметра кліку буває до 1024 символів.
- Без FK на `campaigns`, як і в `clicks`.

### D3. Агрегатор
`App\Reports\StatsAggregator::aggregate(CarbonImmutable $fromUtc, CarbonImmutable $toUtc): int`:
- Межі вирівнюються до годин: `fromUtc` вниз, `toUtc` вгору. Потім цикл по добах UTC, кожна доба — окрема транзакція: `DELETE FROM report_stats WHERE hour >= :a AND hour < :b`, а далі `INSERT ... SELECT`:
  ```sql
  SELECT date_trunc('hour', c.clicked_at), c.campaign_id,
         coalesce(c.params ->> ts.zone_param, ''), coalesce(c.params ->> ts.creative_param, ''),
         coalesce(c.country_code, ''), coalesce(c.device_type, ''), coalesce(c.os_name, ''),
         count(*), count(*) FILTER (WHERE NOT c.is_duplicate), count(*) FILTER (WHERE c.is_bot),
         count(v.id) FILTER (WHERE v.status IN ('lead','hold','approved')), ... ,
         coalesce(sum(v.payout_base) FILTER (WHERE v.status = 'approved'), 0), ...,
         count(*) FILTER (WHERE v.status IN ('approved','hold') AND v.payout IS NOT NULL AND v.payout_base IS NULL)
  FROM clicks c
  LEFT JOIN campaigns cp ON cp.id = c.campaign_id
  LEFT JOIN traffic_sources ts ON ts.id = cp.traffic_source_id
  LEFT JOIN conversions v ON v.click_id = c.click_id
  WHERE c.clicked_at >= :a AND c.clicked_at < :b
  GROUP BY 1, 2, 3, 4, 5, 6, 7
  ```
  `params ->> NULL` дає NULL, тож джерело без обраного параметра дає `''` без окремої гілки. Конверсія приєднується до кліку один до одного (unique `click_id`), тому кліки не множаться. Сирітські конверсії не мають кліку й не потрапляють у вибірку.
- Увесь прогін іде під `Cache::lock('spread:reports:aggregate')->block(600)`, тож планувальник і фонова перебудова чекають одне одного й не перетинаються.
- Повна перебудова: від `min(clicked_at)` до поточної години, плюс `DELETE` рядків поза цим діапазоном (на випадок видалених кліків у майбутньому).

### D4. Команда й розклад
- `spread:reports:aggregate {--days=2} {--from=} {--to=} {--all}`. `--from/--to` — дати `Y-m-d` у таймзоні звітів. Дні переводяться в межі UTC: `from` = 00:00 першого дня, `to` = 00:00 дня після останнього. Для `--days=N` період — від сьогодні − (N−1) до сьогодні. `--all` запускає повну перебудову. Команда виводить період в UTC і кількість рядків.
- `routes/console.php`: `->everyFiveMinutes()->withoutOverlapping()` з дефолтом і `--days=30 ->dailyAt('03:30')->timezone('Europe/Kyiv')->withoutOverlapping()`.

### D5. Фонова перебудова
- Job `App\Jobs\RebuildReportStats` (`ShouldQueue`, `ShouldBeUnique`) викликає повну перебудову. Унікальність не дає кільком збереженням поспіль накласти чергу перебудов.
- Виклики:
  - `Recalculator`: наприкінці `missing()`, `all()` і `forRate()`, якщо перераховано хоча б одну конверсію. Одне місце покриває курси НБУ, ручні курси, команду й зміну базової валюти.
  - `TrafficSource::saved`, якщо `wasChanged(['zone_param', 'creative_param'])`.
- Альтернатива: синхронна перебудова в запиті адмінки. Її відкинуто, бо час росте з кількістю кліків. Фонова задача — саме те, для чого є Horizon.

### D6. Запит звіту
`App\Reports\ReportQuery` приймає `ReportFilter` (дати `from`/`to` у таймзоні звітів, `campaignIds`, `groupBy` з 1–2 значень enum `Dimension`) і повертає `Collection` рядків `ReportRow` з розрізами й сирими сумами. Похідні метрики (CR, прибуток, ROI, EPC) рахує `ReportRow`. Один SQL з CTE:
1. `f` — дрібні рядки `report_stats` за межами UTC періоду й фільтром кампаній, з обчисленими `day = (hour AT TIME ZONE 'UTC' AT TIME ZONE :tz)::date` і `hod = extract(hour ...)`, згруповані за всіма розрізами + `day` + `hod`.
2. Віконні суми: `zone_clicks = sum(clicks) OVER (PARTITION BY campaign_id, day, zone)` і `camp_clicks = ... OVER (PARTITION BY campaign_id, day)`.
3. `zc` / `cc` — витрати з `amount_base IS NOT NULL` за `date BETWEEN from AND to` і кампаніями: по зоні (`zone IS NOT NULL`) і всієї кампанії (`zone IS NULL`).
4. Рядок `f` отримує `cost = coalesce(zc.amount_base * clicks / zone_clicks, 0) + coalesce(cc.amount_base * clicks / camp_clicks, 0)`.
5. `UNION ALL` витрат без кліків: `zc` без відповідника в `f` за (кампанія, день, зона) і `cc` без відповідника за (кампанія, день). Такі рядки отримують нульові лічильники й порожні решту розрізів.
6. Зовнішній `GROUP BY` обраних розрізів із сумами.

Округлення до 2 знаків лише при показі й в CSV. Суми в SQL лишаються numeric, тож підсумок витрат точно дорівнює сумі `amount_base` періоду. Кампанію показано назвою (`LEFT JOIN campaigns` на зовнішньому рівні). Пристрій і ОС показано як є.
Окремий легкий запит рахує попередження: `count` витрат періоду з `amount_base IS NULL` і `sum(no_rate)` з `report_stats`.

### D7. Сторінка «Reports»
- `App\Filament\Pages\Reports` (`HasTable`). Форма над таблицею (період, кампанії multi-select, групування 1 і 2) живе в Livewire-властивостях. Таблиця з `records()` бере `ReportQuery` і сортує й пагінує колекцію в PHP: рядків — сотні чи тисячі, не мільйони. Ключ запису — конкатенація розрізів.
- Колонки розрізів показуються залежно від групування. Колонки метрик `sortable()`, сортування за замовчуванням — кліки за спаданням.
- Підсумки: Filament summarizers рахують через Eloquent-запит, тож для `records()` вони не підходять. Рядок підсумків — view-компонент під таблицею, з того самого `ReportQuery` без групування. Якщо в Filament 5 summarizers працюють із `records()`, можна використати їх, і це варто записати нотаткою під задачею.
- Попередження про курси — `callout` над таблицею, коли лічильники більші за нуль.

### D8. CSV
- «Export CSV» (header action) віддає `response()->streamDownload()` з `fputcsv` з тими ж рядками й сортуванням, що й таблиця. Назва файлу: `report-{from}-{to}.csv`.
- «Loss-making zones» — action з модальною формою: кампанія (одна, за замовчуванням єдина обрана у фільтрі) і поріг витрат (≥ 0, за замовчуванням 0). Запит той самий: `ReportQuery` з групуванням за зоною й фільтром кампанії, далі фільтр у PHP (`zone !== ''`, `profit < 0`, `cost >= threshold`) і сортування за прибутком. Назва: `loss-zones-{alias}-{from}-{to}.csv`. Порожній результат → `Notification` замість файлу.
- Числа форматуються `number_format($x, 2, '.', '')`, а порожні метрики — порожній рядок.

### D9. Дашборд
- В `AdminPanelProvider` замість `FilamentInfoWidget` стоять `App\Filament\Widgets\ProfitOverview` (`StatsOverviewWidget`: три `Stat` з прибутком і описом «revenue X · cost Y») і `TopCampaigns` / `WorstCampaigns` (`TableWidget` з `records()`). `AccountWidget` лишається.
- Усі три використовують `ReportQuery` з групуванням за кампанією (для блоків — без групування) за періодами в таймзоні звітів.

## Risks / Trade-offs

- [Конверсія змінилась у кліку, старшому за 30 днів] → нічна перерахунка її не побачить. Пом'якшення: `spread:reports:aggregate --all` або `--from`. Типові hold закриваються за 30 днів, ширше вікно — Етап 7, якщо знадобиться.
- [Таймзона з півгодинним зсувом (Asia/Kolkata)] → година UTC потрапляє в один день цілком, тож межа дня зсувається на 30 хвилин. Для `Europe/Kyiv` і інших цілогодинних зон похибки немає. Прийнято.
- [Розподіл витрат за кліками для CPM-трафіку] → наближення: CPM платять за покази, а не кліки. Підсумки по кампанії, дню й зоні точні, наближені лише розрізи креатив/гео/пристрій/ОС/година.
- [Зміна таймзони звітів] → день кліків перераховується одразу (обчислюється в запиті), а дати витрат лишаються днями старої таймзони. Таймзону змінюють рідко, це відома особливість 04-costs.
- [Повна перебудова на великих обсягах] → триває довго, але йде у фоні, по добі на транзакцію. Звіт тим часом показує частково перебудовані дні. Прийнятно для одного користувача, а ClickHouse — Етап 10+.
- [Horizon не запущений локально] → фонова перебудова чекає в черзі. Для ручних перевірок запускати `php artisan horizon` або `queue:work --once`.

## Migration Plan

Міграції додають колонки й таблицю без зміни наявних даних. Після деплою — одноразово `php artisan spread:reports:aggregate --all`. Відкат: `migrate:rollback` прибирає `report_stats` і колонки джерела, решта даних не зачіпається.
