# Spec Delta

## Purpose

Тримає в Redis актуальну копію даних кампаній і офферів, потрібних редіректу (знімок конфігу за docs/contract.md), щоб редірект ніколи не звертався до БД і не залежав від решти Laravel.

## ADDED Requirements

### Requirement: Вміст знімка
Знімок SHALL складатися з двох ключів Redis, імена яких абсолютні (без префікса фреймворку):
- `spread:config:campaigns` — hash, де поле — alias кампанії, а значення — JSON `{"id": <int>, "offer_id": <int>, "active": <bool>, "offer_url": "<шаблон URL оффера як є>"}`. У знімку SHALL бути кожна наявна кампанія, і лише вони.
- `spread:config:meta` — рядок JSON `{"v": 1, "version": <int>, "generated_at": "<UTC ISO 8601 з мілісекундами>", "fallback_url": "<URL>"}`, де `version` — час побудови в мілісекундах від епохи Unix, а `fallback_url` — значення з налаштування середовища `SPREAD_FALLBACK_URL`.

#### Scenario: Кампанія в знімку
- **WHEN** існує активна кампанія з id 12, alias `k3v9x0qa2m` і оффером id 5 з шаблоном `https://offer.example/lp?sub1={click_id}&sub2={zone}`, і знімок перебудовано
- **THEN** `HGET spread:config:campaigns k3v9x0qa2m` повертає JSON з `id` 12, `offer_id` 5, `active` true і `offer_url`, рівним шаблону символ у символ

#### Scenario: Вимкнена кампанія
- **WHEN** кампанію вимкнено і знімок перебудовано
- **THEN** її запис у знімку має `active` false

#### Scenario: Meta
- **WHEN** знімок перебудовано при `SPREAD_FALLBACK_URL=https://example.com/`
- **THEN** `spread:config:meta` містить `v` 1, `fallback_url` `https://example.com/`, `generated_at` у форматі `YYYY-MM-DDTHH:MM:SS.mmmZ` і `version`, більший за попередній

#### Scenario: Жодної кампанії
- **WHEN** у БД немає жодної кампанії і знімок перебудовано
- **THEN** ключа `spread:config:campaigns` немає (порожній hash), а `spread:config:meta` записано

### Requirement: Атомарна заміна знімка
Перебудова SHALL замінювати знімок цілком і атомарно: читач MUST NOT бачити суміш старого й нового знімка чи новий hash зі старим meta. Інші ключі Redis (черги, стріми кліків, дані інших застосунків) перебудова MUST NOT зачіпати.

#### Scenario: Видалена кампанія зникає
- **WHEN** кампанію видалено і знімок перебудовано
- **THEN** її alias більше немає в `spread:config:campaigns`, решта кампаній на місці

#### Scenario: Тимчасовий ключ не лишається
- **WHEN** перебудова завершилася
- **THEN** ключа `spread:config:campaigns:tmp` не існує

### Requirement: Коли перебудовується знімок
Знімок SHALL перебудовуватися синхронно після того, як транзакція БД зафіксувала створення, зміну чи видалення кампанії або оффера, а також командою `php artisan spread:config:rebuild`. Якщо транзакцію відкочено, знімок MUST NOT змінюватися. Якщо Redis недоступний під час перебудови, помилка SHALL бути видимою (помилка дії в адмінці чи ненульовий код виходу команди), а зміна в БД лишається збереженою; повторний запуск `spread:config:rebuild` виправляє знімок.

#### Scenario: Нова кампанія одразу в знімку
- **WHEN** адмін створює кампанію в адмінці
- **THEN** одразу після збереження її alias є в `spread:config:campaigns`

#### Scenario: Зміна шаблону оффера
- **WHEN** адмін змінює шаблон URL оффера, прив'язаного до двох кампаній
- **THEN** у знімку обидві кампанії мають новий `offer_url`

#### Scenario: Команда перебудови
- **WHEN** ключі знімка видалено вручну і запущено `php artisan spread:config:rebuild`
- **THEN** обидва ключі знову існують і відповідають БД, команда завершується з кодом 0 і виводить кількість кампаній

#### Scenario: Відкат транзакції
- **WHEN** кампанію змінено всередині транзакції, яку потім відкочено
- **THEN** знімок лишається таким, яким був до транзакції

#### Scenario: Fallback URL не налаштовано
- **WHEN** `SPREAD_FALLBACK_URL` порожній і запущено `php artisan spread:config:rebuild`
- **THEN** команда завершується з ненульовим кодом і повідомленням про відсутній `SPREAD_FALLBACK_URL`, а знімок не змінюється
