# Spread

Personal traffic-arbitrage tracker: one user, one VPS. Laravel 13 (PHP 8.5) with Filament 5 admin,
Horizon, PostgreSQL and Redis; the hot path (redirect, postbacks) moves to a Go service later.

## Code style

<!-- Copied from ~/.claude/code-style (general, php). Refresh with /project-claude-md; don't edit by hand. -->

### Code style — all languages

- Every comment and docblock is written in English, whatever language the conversation or the
  task ticket is in. What is worth commenting at all is covered by "Comments Only When Needed"
  in the global instructions.
- The project's formatter and linter are the source of truth for layout. Where a language file
  disagrees with the project's tooling, the project `CLAUDE.md` records the override.

### Code style — PHP

#### Types

- Full type declarations on params, returns and properties. Avoid `mixed`; nullable types
  are explicit.
- Use only syntax the project's PHP version allows (see `composer.json`).
- PHPDoc only where types can't say it: array/collection generics, array shapes,
  non-obvious behaviour. Never to restate a signature.
- Give generics to `Builder`, `Collection` and criteria classes in PHPDoc.
- Don't add `declare(strict_types=1)` — these projects don't use it.

#### Structure

- Constructor property promotion with `public readonly` where a property never changes.
- `final class` for classes that are not meant to be extended.
- Backed enums over class constants; enum cases in `UPPER_SNAKE`.
- Prefer explicit over implicit.

#### Control flow

- `match` over `switch`.
- Nullsafe `?->` instead of a null check that only guards a call.
- An `if` with several `&&`/`||` checks puts each check on its own line, and the condition
  itself stays simple — no function calls or side expressions inside it.

#### Layout

- 4-space indent; trailing comma in multiline arrays, arguments and parameter lists.
- No stray blank lines; a blank line separates `use` groups, not statements.
- A closure that immediately returns a single expression is an arrow function.

#### Query builder

- Always pass the comparison operator explicitly: `->where('field', '=', $value)`.

### Overrides for this repo

- Pint with the default `laravel` preset (no `pint.json`) is the layout authority.
- Classes made by Laravel/Filament generators (models, providers, resources, pages, schemas,
  tables) keep the generator's shape and are not `final` — all 35 classes in `app/` follow this.

## Layout

| Path | What |
|---|---|
| `docs/roadmap.md` | Stages 0–10 with "done when" criteria; source of scope |
| `docs/contract.md` | Hot-path contract: Redis keys, config snapshot, click message (`v`) |
| `openspec/specs/` | Current capability specs; `openspec/changes/` active changes, `archive/` done |
| `openspec/config.yaml` | Project context and artifact rules for OpenSpec |
| `app/Models/` | `TrafficSource`, `CpaNetwork`, `Offer`, `Campaign`, `User` |
| `app/Filament/Resources/<Name>/` | Resource + `Pages/`, `Schemas/` (form, infolist), `Tables/` |
| `config/spread.php` | App settings (`tracker_url`) |
| `tests/Feature`, `tests/Unit` | PHPUnit; Filament pages tested via Livewire |

## Backend

- Run every php/composer/artisan command in the Laradock workspace, as uid 1000:
  `docker exec -u laradock -w /var/www/Spread laradock_all-workspace-85-1 <cmd>`
  (start it with `docker start laradock_all-workspace-85-1` if stopped). Never run php as root.
- In-container hosts: `postgres` (DB `spread`, tests `spread_testing`), `redis`.
  Web: `http://spread.local` → nginx → `php-fpm-85`.
- Queue on Redis via phpredis; session and cache on `database`. All times UTC.
- Hot path isolation: the redirect and postback intake use no Eloquent models and nothing else
  from the app — only `docs/contract.md`. Contract changes go into that file in the same commit.
- Redis keys from the contract are absolute (`spread:*`), without the Laravel Redis prefix.
- Delete-blocking pattern: DB `restrictOnDelete` + `DeleteAction->before()` notification and
  cancel + `DeleteBulkAction->using()` with `reportBulkProcessingFailure()`; test all three.
- Admin UI strings are English (`APP_LOCALE=en`).
- Checks: `php artisan test`, `./vendor/bin/pint --test` (fix: `./vendor/bin/pint`).

## Workflow

- Every code change goes through OpenSpec: propose → apply → archive (`openspec` CLI in WSL,
  `openspec validate <change> --strict`). Change names `NN-slug`, NN = roadmap stage.
- Agents: `openspec-proposer` writes artifacts, `openspec-implementer` does one task;
  `openspec-git-operator` is not used here.

## Build & git

- Integration branch `main`. Branches: `spec/propose/NN-slug`, `feature/NN-slug`,
  `task/NN-slug/<what>`, `spec/archive/NN-slug`; merges are `--no-ff` with `Merge <branch>`.
- Commit prefixes: `spec:` for OpenSpec-only commits, `feat:`/`fix:`/`refactor:`/`chore:` for code.
- Git commands only with the user's go-ahead; no remote yet — never push without asking.
- Filament assets in `public/{css,js,fonts}/filament` are generated by `filament:upgrade`
  (composer `post-autoload-dump`) and gitignored.

## Care

- `.env` holds secrets and is gitignored; `.env.example` carries Laradock's default DB password only.
- Laradock is shared with other projects: `php-fpm-85` also serves another site, and
  `laradock/php-fpm/Dockerfile` was patched to restore phpredis (backup `Dockerfile.bak-2026-09-24`).
- `migrate:fresh` / `db:wipe` on `spread` deletes the admin user — ask first.
- Don't commit Laravel Boost `CLAUDE.md`/`AGENTS.md` if a package reinstalls them.
