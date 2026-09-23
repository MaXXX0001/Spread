# Design: 01-skeleton

## Context

Репозиторій містить лише `docs/` і `openspec/`, Laravel ще не встановлено. Середовище — Laradock у WSL, і воно спільне з іншими проєктами. Команди PHP запускаються в контейнері `laradock_all-workspace-85-1` (PHP 8.5, розширення pgsql, redis і pcntl є). Веб обслуговує `nginx` → `php-fpm-85:9000`.

Що встановлено під час дослідження (читання, без змін):
- у `php-fpm-85` спершу не було розширень `redis` і `pcntl`. Redis виправлено 2026-09-24 (див. D8), pcntl вимкнено свідомо (`PHP_FPM_INSTALL_PCNTL=false`);
- наявні nginx-сайти (наприклад, `85_voyager.conf`) не належать Laravel-проєктам, тому для Spread потрібен стандартний Laravel-конфіг з `root .../public` і `try_files`.

Мотивацію описано в proposal.md, вимоги — у specs/.

## Goals / Non-Goals

**Goals:**
- Робочий каркас Laravel + Filament + Horizon, на який Етап 2 додасть редірект, нічого не переробляючи.
- Схема БД для чотирьох довідників, узгоджена з docs/contract.md: `campaigns.alias`, `campaigns.active`, `offers.url_template` → `offer_url` знімка.

**Non-Goals:**
- Нічого з гарячого шляху: маршруту `/c/{alias}`, Redis-знімка, observer'ів, які його оновлюють, і команди `spread:config:rebuild` тут немає.
- Жодних ролей і політик доступу: користувач один.
- Жодних сидерів чи демо-даних.
- Деплой на VPS.

## Decisions

### D1. Встановлення Laravel у непорожній репозиторій
`composer create-project laravel/laravel` у тимчасову теку `/var/www/spread-install`, потім `rsync -a` її вмісту в `/var/www/Spread` (файли `docs/` і `openspec/` каркас не перетинає), потім тимчасову теку видалити. Альтернатива `laravel new .` відмовляє в непорожній теці. Файл `database/database.sqlite`, який створює інсталятор, видаляємо, бо працюємо на PostgreSQL. `README.md` Laravel лишається як є.

### D2. Драйвери
- `DB_CONNECTION=pgsql`, host `postgres`, база `spread`, user `default`.
- `REDIS_CLIENT=phpredis`, host `redis`.
- `QUEUE_CONNECTION=redis`, тому що цього вимагає Horizon.
- `SESSION_DRIVER` і `CACHE_STORE` лишаються на дефолтному `database`. Причина: для одного користувача це менше рухомих частин, і сесії й кеш не залежать від Redis. Перейти на `redis` пізніше — питання двох рядків у `.env`.
- `APP_TIMEZONE`/`config('app.timezone')` = `UTC` (у Laravel це й так дефолт). Колонки часу — стандартні `timestamps()`.

### D3. Тестова БД
Окрема PostgreSQL-база `spread_testing` (задається в `phpunit.xml`: `DB_CONNECTION=pgsql`, `DB_DATABASE=spread_testing`), тести з `RefreshDatabase`. SQLite у пам'яті відкинуто: шаблон макросів зберігається в `jsonb`, а обмеження FK і унікальності мають перевірятися на тій самій СУБД, що й у продакшні.

### D4. Адмінка й доступ
- Filament 5, одна панель `admin` за шляхом `/admin`, `->login()` без `->registration()` і без `->passwordReset()`.
- `App\Models\User` реалізує `FilamentUser`, `canAccessPanel()` повертає `true`: доступ дає сам факт існування облікового запису, а створити його можна лише з консолі (`make:filament-user`).
- Horizon: у `App\Providers\HorizonServiceProvider` перевизначаємо `authorization()` так: `Horizon::auth(fn ($request) => $request->user() !== null)`. Стандартна поведінка пускає всіх у `local`, а spec вимагає входу в будь-якому середовищі. Guard `web` спільний з Filament, тому сесія адмінки діє і для `/horizon`. Гість отримує 403 (стандартна реакція Horizon), редіректу на логін немає. Для одного користувача цього досить.

### D5. Схема БД

| Таблиця | Колонки |
|---|---|
| `traffic_sources` | `id`, `name` string unique, `macros` jsonb (масив `{"param": "...", "macro": "..."}`), timestamps |
| `cpa_networks` | `id`, `name` string unique, `notes` text nullable, timestamps |
| `offers` | `id`, `cpa_network_id` FK restrict, `name` string, `url_template` string(2048), timestamps |
| `campaigns` | `id`, `name` string, `alias` string(10) unique, `traffic_source_id` FK restrict, `offer_id` FK restrict, `active` bool default true, timestamps |

- Макроси зберігаються **списком** пар, а не об'єктом. Причина: `jsonb` у PostgreSQL не зберігає порядок ключів об'єкта, а порядок параметрів у посиланні має відповідати введеному. У формі це Filament `Repeater` з полями `param` і `macro`. `KeyValue` відкинуто, бо він зберігає об'єкт.
- `restrictOnDelete()` на всіх FK захищає на рівні БД. Filament-дія видалення перевіряє зв'язки заздалегідь і показує зрозуміле повідомлення замість помилки SQL. Soft deletes не потрібні.
- Назва оффера не унікальна: однаковий оффер може бути в різних мережах. Назва кампанії теж не унікальна: ідентифікує її alias.

### D6. Alias
Генерується в Eloquent-події `creating` моделі `Campaign`: 10 символів, кожен обирається `random_int(0, 35)` з алфавіту `a-z0-9` (≈ 3.6·10¹⁵ варіантів). Перед записом перевіряється, що такого alias ще немає; при збігу генерується новий. Остаточну унікальність гарантує unique-індекс. `Str::random()` відкинуто: він дає мішаний регістр, а приведення до нижнього регістру нерівномірно розподіляє символи. Поле `alias` не входить до `$fillable` і показується у формі як disabled/read-only. Формат узгоджено з контрактом: alias — ключ hash `spread:config:campaigns`, а в Етапі 2 його читатиме редірект.

### D7. Трекінгове посилання
- Конфіг `config/spread.php` → `'tracker_url' => env('SPREAD_TRACKER_URL', env('APP_URL'))`. Локально `SPREAD_TRACKER_URL=http://spread.local`. Файл `config/spread.php` згодом розшириться (наприклад, під fallback URL в Етапі 2), але зараз у ньому лише цей ключ.
- Метод `Campaign::trackingUrl(): string` у моделі, бо логіка на кілька рядків і має одного споживача. Кроки: `rtrim(base, '/')` + `/c/` + alias, потім пари `param=macro`, з'єднані через `&` **без** `http_build_query` чи `urlencode`. Інакше `{zoneid}` перетвориться на `%7Bzoneid%7D`, і мережа не підставить значення. Назви параметрів обмежено `[A-Za-z0-9_]`, тож кодувати їх не потрібно.
- Сторінка `ViewCampaign` з infolist: `TextEntry` з `->copyable()`. Filament сам показує підтвердження копіювання. У таблиці посилання не показуємо, щоб не перевантажувати список: там видно alias.
- Метод використовує лише адмінка. Редірект Етапу 2 його не викликає: він працює зі знімком за контрактом.

### D8. phpredis/pcntl у php-fpm-85 (вирішено)
Причина: у `laradock/php-fpm/Dockerfile` блок «PHP REDIS EXTENSION» був порожній (код встановлення видалено), тому `PHP_FPM_INSTALL_PHPREDIS=true` ні на що не впливав. 2026-09-24 з дозволу користувача блок відновлено (`pecl install -o -f redis` + `docker-php-ext-enable redis`, бекап `php-fpm/Dockerfile.bak-2026-09-24`), образ перебудовано (`docker compose build php-fpm-85`), контейнер перестворено (`docker compose up -d --no-deps php-fpm-85`). Тепер php-fpm-85 працює на PHP 8.5.10 з phpredis 6.3.0, підключення до `redis` з контейнера працює, решта модулів на місці, сайт voyager відповідає 200. Predis не використовується, клієнт — `phpredis`, як і вирішено для стеку.

pcntl у php-fpm лишається вимкненим свідомо: він потрібен лише CLI-процесу `php artisan horizon`, який запускається у workspace-85, а там pcntl є.

### D9. Локальний nginx-сайт
Файл `laradock/nginx/sites/85_spread.conf` (префікс `85_` за версією PHP, як у сусідніх файлах):

```nginx
server {
    listen 80;
    server_name spread.local;
    root /var/www/Spread/public;
    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass php-fpm-85:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Цей файл лежить поза репозиторієм Spread, у спільному Laradock. Тому його створення, перезавантаження nginx і запис `127.0.0.1 spread.local` у `C:\Windows\System32\drivers\etc\hosts` виконує користувач (задачі 1.2–1.3).

## Risks / Trade-offs

- [Посилання кампанії веде на 404, доки не зроблено Етап 2] → Прийнятно: у рекламну мережу його ставлять не раніше ніж з'явиться редірект. Критерій «готово» Етапу 1 — лише скопіювати посилання.
- [Зміна макросів джерела змінює посилання вже запущених кампаній] → Так задумано (spec campaigns). Старі посилання теж продовжать працювати: редірект приймає будь-які query-параметри.
- [Filament 5 / Horizon ще можуть не мати сумісних з Laravel 13 версій на момент встановлення] → Задачі 2.1–2.3 починаються з `composer require` і зупиняються з повідомленням користувачу, якщо Composer не знаходить сумісної версії. Даунгрейд стеку без рішення користувача неприпустимий.
- [Filament `copyable()` працює лише в безпечному контексті (HTTPS або localhost); на `http://spread.local` браузер може заблокувати Clipboard API] → Перевірити вручну в задачі 4.1. Якщо заблоковано, найпростіше рішення — дозволити в Chrome insecure origin для `spread.local` (`chrome://flags/#unsafely-treat-insecure-origin-as-secure`) або відкрити через `localhost`. Код не змінюємо.
- [Спільний Laradock] → Жодна задача агента не змінює файли й контейнери Laradock. Лише створення баз у спільному postgres (задача 1.1) торкається його, і це додавання, а не зміна. Перебудову php-fpm-85 (D8) вже виконано з дозволу користувача.

## Migration Plan

Перше встановлення, відкочувати нічого. Порядок: інфраструктурні кроки 1.x, далі код-задачі 2.x → 3.x, наприкінці ручна перевірка 4.x.
