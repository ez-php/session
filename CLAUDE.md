# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks every
  `CLAUDE.md` copy as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/session

Session handler drivers (File/Database/Redis/Array), `StartSessionMiddleware`, flash data, and session id regeneration for ez-php applications.

---

## Source Structure

```
src/
├── SessionException.php              — Exception for driver failures and use-before-session-active errors
├── Flash.php                         — Flash data: set() this request, readable for exactly one subsequent request
├── SessionRegenerator.php            — session_regenerate_id() helpers: regenerate(), regenerateIfStale()
├── StartSessionMiddleware.php        — MiddlewareInterface: registers the driver, starts the session, ages flash data
├── SessionServiceProvider.php        — Reads config/session.php, binds SessionHandlerInterface to the selected driver
└── Driver/
    ├── ArraySessionHandler.php       — In-process driver; process lifetime only (tests, CLI)
    ├── FileSessionHandler.php        — Filesystem driver; sess_<id> files, path-traversal-safe id validation
    ├── DatabaseSessionHandler.php    — PDO-backed driver via DatabaseInterface; auto-creates `sessions` table
    └── RedisSessionHandler.php       — ext-redis driver; native TTL, no gc() sweep needed

tests/
├── TestCase.php                      — Base PHPUnit test case
├── SessionPdoDatabase.php                   — Minimal DatabaseInterface fixture backed by raw PDO (avoids a framework dependency)
├── FlashTest.php                     — Aging, set/get round-trip, keep(), forget(), all(), not-active guard
├── SessionRegeneratorTest.php        — regenerate(), regenerateIfStale() interval logic, not-active guard
├── StartSessionMiddlewareTest.php    — Session start, idempotent re-entry, flash aging, periodic regeneration
├── SessionServiceProviderTest.php    — Driver selection from config, unknown-value fallback
├── Support/
│   ├── FakeConfig.php                — Minimal ConfigInterface stub
│   └── FakeContainer.php             — Minimal ContainerInterface stub (bindings + instances)
└── Driver/
    ├── ArraySessionHandlerTest.php
    ├── FileSessionHandlerTest.php    — Includes path-traversal rejection and gc() via touch()-backdated mtimes
    ├── DatabaseSessionHandlerTest.php — Runs against SQLite :memory: via Tests\SessionPdoDatabase
    └── RedisSessionHandlerTest.php   — Requires live Redis; skipped when ext-redis is unavailable
```

---

## Key Classes and Responsibilities

### The driver contract

All four drivers implement PHP's native `\SessionHandlerInterface` (`open`, `close`, `read`, `write`, `destroy`, `gc`) rather than a module-defined interface — sessions are inherently a native PHP mechanism (`$_SESSION`, `session_set_save_handler()`), and every other piece of session-reading code in this monorepo (`ez-php/auth`'s `Auth`, `ez-php/framework`'s `SessionCsrfTokenStore`) already assumes `$_SESSION` is the access point. Building a parallel `SessionInterface` would mean two ways to read the same data.

### ArraySessionHandler (`src/Driver/ArraySessionHandler.php`)

In-memory `array<string, array{data: string, timestamp: int}>`. Data lives for the process lifetime only — a real request/response cycle is one process per request, so this driver is for tests and single-process CLI usage, not production web traffic.

### FileSessionHandler (`src/Driver/FileSessionHandler.php`)

Filesystem driver. Each session is `sess_<id>` under the configured directory (`LOCK_EX` on write, matching `ez-php/cache`'s `FileDriver`). `pathFor()` validates the id against PHP's own session-id charset (`[a-zA-Z0-9,-]+`) before building a path, and throws `SessionException` otherwise — defence in depth against path traversal via a crafted cookie, in case a non-default `session.sid_bits_per_character` or a custom id generator ever widens what reaches the handler. `gc()` scans `sess_*` and removes files whose mtime is older than `$max_lifetime`.

### DatabaseSessionHandler (`src/Driver/DatabaseSessionHandler.php`)

PDO-backed via `DatabaseInterface` from `ez-php/contracts` (not a raw `PDO` constructor parameter — keeps this module usable with any `DatabaseInterface` implementation, not just `ez-php/framework`'s `Database`). Auto-creates a `sessions` table (`CREATE TABLE IF NOT EXISTS`, driver-aware DDL for MySQL/SQLite), the same pattern `ez-php/queue`'s `DatabaseDriver` uses for `jobs`/`failed_jobs`. `write()` is UPDATE-then-INSERT-if-zero-rows rather than a driver-specific upsert (`ON DUPLICATE KEY` / `INSERT OR REPLACE`), so the same code runs unchanged against MySQL and SQLite.

### RedisSessionHandler (`src/Driver/RedisSessionHandler.php`)

`ext-redis`-backed, keys under `session:<id>`, written with `SETEX` using the configured TTL. Expiry is entirely Redis-native — `gc()` is a no-op, unlike the other three drivers.

### Flash (`src/Flash.php`)

Flash data operates directly on `$_SESSION['_flash']` (documented global state — see Design Decisions). Storage shape: `display` (readable this request) and `next` (queued for the request after this one). `age()` promotes `next` → `display` and resets `next`; it must run exactly once per request, before any read, which is why `StartSessionMiddleware` calls it unconditionally on every `handle()`. `keep()` re-queues an already-readable key for one more round by calling `set()` with its current `display` value.

### SessionRegenerator (`src/SessionRegenerator.php`)

Thin wrapper over `session_regenerate_id()`. `regenerate()` is unconditional; `regenerateIfStale(int $intervalSeconds)` checks (and updates) a `_session_regenerated_at` timestamp in `$_SESSION`, returning whether it actually regenerated. Deliberately independent of authentication — `ez-php/auth`'s `Auth::login()`/`logout()` already call `session_regenerate_id()` directly at the security-relevant moments (login, logout). This class is for *periodic* regeneration of a long-lived authenticated (or anonymous) session, to shrink the window a fixed id stays valid — a different concern from auth-event-triggered regeneration, not a replacement for it.

### StartSessionMiddleware (`src/StartSessionMiddleware.php`)

Implements `MiddlewareInterface` from `ez-php/contracts`. On `handle()`:

1. If no session is active: `session_set_save_handler($handler, true)` then `session_start()`. If a session is already active (started earlier in the pipeline, or by a test harness), this step is skipped entirely — registering a save handler on an already-active session throws.
2. `Flash::age()` — unconditional, every request.
3. If `session.regenerate_interval` (read via `ConfigInterface`, not a constructor scalar — see Design Decisions) is `> 0`, calls `SessionRegenerator::regenerateIfStale()` with that interval.
4. Calls `$next($request)`.

This formalises the `SessionStartMiddleware` example already documented in `framework/CLAUDE.md` § CSRF Protection — that example is a two-line ad hoc middleware; this module's version adds configurable driver selection, flash aging, and optional periodic regeneration on top of the same "start the session before anything downstream reads it" contract. Must run before `ez-php/framework`'s `CsrfMiddleware` (its `SessionCsrfTokenStore` reads `$_SESSION`) and before any `ez-php/auth` middleware.

### SessionServiceProvider (`src/SessionServiceProvider.php`)

Reads `config/session.php` and binds `\SessionHandlerInterface` lazily to the driver selected by `session.driver`.

| Config key | Type | Default | Meaning |
|---|---|---|---|
| `session.driver` | string | `'file'` | `'array'`, `'file'`, `'database'`, or `'redis'` |
| `session.file.path` | string | `sys_get_temp_dir() . '/ez-session'` | Directory for `FileSessionHandler` |
| `session.database.table` | string | `'sessions'` | Table name for `DatabaseSessionHandler` |
| `session.redis.host` | string | `'127.0.0.1'` | Redis hostname |
| `session.redis.port` | int | `6379` | Redis port |
| `session.redis.database` | int | `0` | Redis database index |
| `session.redis.ttl` | int | `1440` | Redis key TTL (seconds); mirrors PHP's default `session.gc_maxlifetime` |
| `session.regenerate_interval` | int | `0` | Seconds between automatic id regenerations; `0` disables the feature |

Unknown driver values fall back to `FileSessionHandler`. `StartSessionMiddleware` is **not** auto-registered — add it to the global middleware stack explicitly, the same pattern `ez-php/rate-limiter`'s `ThrottleMiddleware` and `ez-php/framework`'s `CsrfMiddleware` use.

---

## Design Decisions and Constraints

- **`\SessionHandlerInterface`, not a module-owned interface.** See "The driver contract" above — this is the one module in the monorepo where matching PHP's own native mechanism is the right call, rather than following the `CacheInterface`/`RateLimiterInterface` pattern of a project-defined contract with N drivers behind it.
- **`Flash` and `SessionRegenerator` are documented global state.** Both operate on `$_SESSION` directly via static methods rather than an injected, constructor-wired instance. This mirrors how `ez-php/auth`'s `Auth` and `ez-php/framework`'s `SessionCsrfTokenStore` already work — `$_SESSION` is PHP's own global, and wrapping it in a DI-friendly instance would not remove the global state, only hide it behind an extra layer that every other session consumer in this monorepo doesn't have.
- **`StartSessionMiddleware` reads `regenerate_interval` from `ConfigInterface` at call time, not a constructor scalar.** A plain `int $regenerateInterval` constructor parameter would need an explicit binding for the container to autowire it (primitives are not autowireable), forcing users to configure the middleware in a service provider by hand instead of `config/session.php`. Injecting `ConfigInterface` (always bound by the core `ConfigServiceProvider`) keeps `StartSessionMiddleware::class` addable via `$app->middleware()` with zero extra wiring.
- **`DatabaseSessionHandler::write()` is UPDATE-then-INSERT, not an upsert.** `ON DUPLICATE KEY UPDATE` (MySQL) and `INSERT OR REPLACE` (SQLite) are different syntax; writing one code path that works against both trades one extra round-trip for driver portability, consistent with this module's "no driver-specific SQL beyond table DDL" scope.
- **No static `Session` facade.** Unlike `Cache`, `RateLimiter`, or `Flag`, there is no `Session::get()`/`Session::put()` static wrapper over `$_SESSION` itself — plain `$_SESSION[...]` access already works once `StartSessionMiddleware` has started the session, and adding a facade that just proxies array access would be a distinction without a difference. `Flash` and `SessionRegenerator` exist as separate static classes because they hold actual behaviour (aging, staleness checks), not because "a static session facade" was the goal.
- **`ez-php/auth` is unmodified.** This module supplies the session infrastructure `ez-php/auth` already assumes exists (an active `$_SESSION`) — see `modules/auth/src/Auth.php`'s `session_status() === PHP_SESSION_ACTIVE` guards. No auth or CSRF logic lives here; `SessionCsrfTokenStore` in `ez-php/framework` also continues to work unchanged once `StartSessionMiddleware` runs ahead of it.

---

## Testing Approach

- **`ArraySessionHandlerTest`, `FileSessionHandlerTest`, `DatabaseSessionHandlerTest`** — No external infrastructure beyond SQLite `:memory:` (via `Tests\SessionPdoDatabase`, mirroring `ez-php/orm`'s own fixture, to avoid a `ez-php/framework` test dependency). `gc()` expiry is tested by manipulating timestamps directly (`gc(-1)`, or backdating a file's mtime / a row's `last_activity`) rather than sleeping.
- **`RedisSessionHandlerTest`** — Requires a live Redis instance (available via Docker). Skipped automatically when `ext-redis` is not loaded or the server is unreachable. Uses Redis database `3`.
- **`FlashTest`, `SessionRegeneratorTest`** — Run against a real PHP session (`session_set_save_handler(new ArraySessionHandler(), true)` + `session_start()` in `setUp()`, `session_destroy()` + `session_write_close()` in `tearDown()`), not a mock — session behaviour (id regeneration, `$_SESSION` semantics) is exactly what these classes exist to wrap, so faking it would not test anything real.
- **`SessionServiceProviderTest`** — Uses `Tests\Support\FakeContainer`/`FakeConfig`, the same minimal-stub pattern as `ez-php/feature-flags`'s `FeatureFlagServiceProviderTest`, avoiding a `ez-php/testing-application` dev dependency.
- **`#[UsesClass]` required** — PHPUnit is configured with `beStrictAboutCoverageMetadata=true`. Test fixtures under `tests/Support/` and `tests/SessionPdoDatabase.php` are outside `src/` and are **not** valid `#[UsesClass]` targets (PHPUnit rejects them as coverage targets) — reference them via a `@uses` PHPDoc tag on the test class instead, matching `ez-php/feature-flags`'s convention.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---|---|
| Authentication (login/logout, remember-me) | `ez-php/auth` |
| CSRF token storage/validation | `ez-php/framework` (`CsrfMiddleware`, `SessionCsrfTokenStore`) |
| General-purpose caching | `ez-php/cache` |
| Rate limiting | `ez-php/rate-limiter` |
| Non-session cookie handling | Application layer / `ez-php/http`'s `Response` header helpers |
