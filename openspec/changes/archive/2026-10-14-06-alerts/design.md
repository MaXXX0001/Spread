# Design: 06-alerts

## Context

Після 05-reports є:
- `ReportQuery` (`rows()`, `total()`, `missingRates()`) за днями в таймзоні звітів поверх `report_stats`, яку планувальник перераховує кожні 5 хвилин;
- `clicks` з індексом (`campaign_id`, `clicked_at`); `conversions` з `campaign_id` (null для сирітських) і `created_at`; `postbacks` зі `state` (`failed` тощо) і `processed_at`;
- Redis-стріми `spread:clicks`, `spread:postbacks` (оброблене видаляється через `XDEL`, тож найстаріший запис — найстаріший необроблений) і `spread:clicks:dead`, `spread:postbacks:dead`. ID запису стріму починається з мілісекунд часу запису;
- `Setting::current()` — шаблон «один рядок з налаштуваннями, а поки його немає — значення за замовчуванням».

Планувальник локально не запущений (етап 7). Мотивація й рішення користувача — у proposal.md, вимоги — у specs/.

## Goals / Non-Goals

**Goals:**
- Перевірка й відправлення не залежать від воркера черги Laravel: алерт про зупинені воркери мусить дійти, навіть коли стоїть Horizon.
- Одне місце відповідає за «надіслати / нагадати / resolved», а самі перевірки лише кажуть, які предмети зараз у проблемі.

**Non-Goals:**
- Історія надісланих алертів в адмінці. Повідомлення лежать у Telegram, а помилки доставки — у лозі.
- Пороги для окремих кампаній, кілька отримувачів, команди боту.

## Decisions

### D1. Налаштування
- Таблиця `alert_settings` з одним рядком: `chat_id` bigint nullable, `reminder_hours`, а також для кожного типу `*_enabled` boolean і пороги (`roi_min_cost`, `roi_max` numeric; `silence_hours`, `silence_min_clicks`; `cr_max` numeric, `cr_min_clicks`; `buffer_max_age_minutes`, `buffer_max_length`; `errors_enabled`), `daily_enabled`, `daily_time` (string `HH:MM`), `daily_sent_on` date nullable, `timestamps`.
- Модель `AlertSetting::current()` повторює `Setting::current()`: збережений рядок або незбережений з атрибутами за замовчуванням (константа-масив `DEFAULTS`).
- Окремий рядок, а не нові колонки в `settings`: налаштувань звітів два, алертів — понад п'ятнадцять, і в них свій життєвий цикл.
- Filament-сторінка `App\Filament\Pages\Alerts` у тій самій формі, що й `Settings`: секції за типами, у кожній перемикач і пороги. Дії «Save» і «Send test message».
- Токен: `config('services.telegram.bot_token')` ← `TELEGRAM_BOT_TOKEN`, у `.env.example` — порожній рядок.

### D2. Клієнт Telegram
`App\Alerts\TelegramClient::send(string $text): void` викликає `Http::timeout(10)->post("https://api.telegram.org/bot{token}/sendMessage", ['chat_id' => …, 'text' => …, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true])`. Немає токена чи chat_id, мережева помилка, не-200 або `ok: false` → виняток `TelegramUnavailable` з описом. Хто викликає, той вирішує, що далі: сторінка показує помилку, рушій пише в лог і пробує на наступній перевірці. Назви кампаній екрануються через `e()`, бо `parse_mode` — HTML.

### D3. Перевірки
Інтерфейс `App\Alerts\Check`:
- `type(): AlertType`;
- `problems(AlertSetting $settings): array<string, string>` — предмет → текст проблеми (уже з цифрами).

Backed enum `AlertType`: `ROI`, `SILENCE`, `HIGH_CR`, `BUFFER`, `ERRORS`. Перевірки:
- `RoiCheck`, `HighCrCheck` — `ReportQuery::rows()` за сьогодні з групуванням за кампанією; фільтр у PHP за порогами (`bccomp`). Предмет — `campaign:{id}`.
- `SilenceCheck` — один SQL: кліки активних кампаній за `now − N год` (`count(*) >= M`) і `NOT EXISTS` конверсії кампанії з `created_at` у вікні. Сирі таблиці, а не агрегат, бо вікно в годинах ковзне і точність агрегату тут не потрібна.
- `BufferCheck` — для кожного стріму `XLEN` і `XRANGE key - + COUNT 1`; вік = now − мілісекунди з ID. Через з'єднання `spread` (абсолютні ключі, як у воркерів). Предмет — назва стріму.
- `ErrorsCheck` — предмет `postbacks`: `count(*) where state = 'failed' and processed_at >= now − 15 хв`; предмет `dead`: для обох dead-стрімів `XRANGE key (now−15хв)-ms + ` з підрахунком записів.

### D4. Рушій станів
Таблиця `alert_states`: `type` string, `subject` string, `opened_at`, `last_sent_at` nullable, `message` text, unique (`type`, `subject`).

`App\Alerts\AlertEngine::run(AlertSetting $settings, list<Check> $checks)`. Для кожної перевірки:
- тип вимкнено → видалити його стани й нічого не надсилати;
- інакше `problems` → для кожного предмета:
  - немає стану → створити з `last_sent_at` null;
  - є стан → оновити `message`;
- надіслати, де `last_sent_at` null (перше повідомлення) або `last_sent_at <= now − reminder_hours` (нагадування «still … for 3h 10m»). Успішна доставка ставить `last_sent_at = now`, а помилка лишає стан як є, тож наступна перевірка повторить;
- стани, предметів яких немає в `problems`:
  - якщо повідомлення про проблему було надіслане → надіслати «resolved» і видалити стан при успіху;
  - якщо воно так і не було доставлене → видалити стан мовчки.

Одна перевірка, що впала винятком (наприклад, Redis недоступний), пишеться в лог і не зупиняє решту. Її стани тим часом не змінюються, бо «resolved» через помилку був би неправдою.

### D5. Команди й розклад
- `spread:alerts:check` будує налаштування й список перевірок і викликає рушій.
- `spread:alerts:daily {--now}` будує `DailyReport::text(CarbonImmutable $day)` і надсилає. Без `--now` спрацьовує, коли виконано все:
  - звіт увімкнено;
  - локальний час не раніше `daily_time`;
  - `daily_sent_on` ≠ сьогодні.
  
  Після доставки ставить `daily_sent_on = сьогодні`; помилка → лог і ненульовий код.
- `routes/console.php`: обидві команди `->everyMinute()->withoutOverlapping()`. Час звіту читається з БД під час виконання, а не під час завантаження розкладу: так зміна часу не потребує перезапуску, а `schedule:list` не ходить у БД.
- Відправлення синхронне, з команди (див. Goals), а не через job.

### D6. Тексти
Повідомлення англійською, як і адмінка. Гроші у форматі `ReportRow::format()` з базовою валютою. Шаблон:
- `🔴 <b>ROI below -30%</b>` + рядок кампанії + цифри;
- нагадування: `🟠 Still: …` + тривалість;
- завершення: `✅ Resolved: …`.

У кампанійних повідомленнях є посилання на сторінку звітів (`config('app.url')/admin/reports`).

## Risks / Trade-offs

- [Laravel чи БД лежать] → алертів немає. Пом'якшення: етап 7 (зовнішній моніторинг) і етап 8 (Go-бот). Це прийнято в proposal.
- [Агрегат відстає до 5 хвилин] → ROI- і CR-алерти приходять із запізненням до 5 хвилин. Для «не встигнути злити бюджет» прийнятно.
- [Вимкнений воркер без трафіку] → алерту немає, бо буфер порожній. Прийнято: нема трафіку — нема що втрачати.
- [Мітка часу кліку й час надходження конверсії] → «без конверсій» рахує конверсії за часом створення. Конверсія пізнього кліку теж знімає тишу, і це правильно: оффер живий.
- [Telegram недоступний годинами] → перше повідомлення повторюється щохвилини до успіху, а лог росте. Прийнятно для одного користувача.
- [chat_id помилковий] → кожна спроба падає з `chat not found` у лозі. Кнопка тестового повідомлення ловить це одразу.

## Migration Plan

Міграції створюють дві нові таблиці. Після деплою в `.env` треба додати `TELEGRAM_BOT_TOKEN`, а на сторінці «Alerts» зберегти chat_id і надіслати тестове повідомлення. Відкат: `migrate:rollback`, а також прибрати розклад.
