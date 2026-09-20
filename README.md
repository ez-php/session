# ez-php/session

Session handler drivers (File/Database/Redis/Array), `StartSessionMiddleware`, flash data, and session id regeneration for ez-php applications.

---

## Installation

```bash
composer require ez-php/session
```

---

## Usage

### 1. Register the service provider

```php
$app->register(\EzPhp\Session\SessionServiceProvider::class);
```

### 2. Add `StartSessionMiddleware` before anything that reads the session

```php
$app->middleware(\EzPhp\Session\StartSessionMiddleware::class);
```

Add it before `ez-php/framework`'s `CsrfMiddleware` and before any `ez-php/auth` middleware — both read `$_SESSION` and assume a session is already active.

### 3. Configure the driver

`config/session.php`:

```php
<?php

return [
    'driver' => getenv('SESSION_DRIVER') ?: 'file',

    'file' => [
        'path' => sys_get_temp_dir() . '/ez-session',
    ],

    'database' => [
        'table' => 'sessions',
    ],

    'redis' => [
        'host' => getenv('SESSION_REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('SESSION_REDIS_PORT') ?: 6379),
        'database' => (int) (getenv('SESSION_REDIS_DATABASE') ?: 0),
        'ttl' => (int) (getenv('SESSION_REDIS_TTL') ?: 1440),
    ],

    // 0 disables periodic regeneration; StartSessionMiddleware otherwise
    // regenerates the session id once this many seconds have elapsed.
    'regenerate_interval' => (int) (getenv('SESSION_REGENERATE_INTERVAL') ?: 0),
];
```

| Driver | Value | Notes |
|---|---|---|
| Array | `array` | In-process only; for tests and CLI |
| File | `file` (default) | Files under `session.file.path` |
| Database | `database` | `sessions` table, created automatically |
| Redis | `redis` | Native TTL, no `gc()` sweep needed |

### 4. Flash data

```php
use EzPhp\Session\Flash;

Flash::set('message', 'Saved successfully.'); // readable on the *next* request
Flash::get('message');                        // readable on *this* request, one round only
Flash::keep('message');                       // extend it for one more request
```

### 5. Session id regeneration

```php
use EzPhp\Session\SessionRegenerator;

SessionRegenerator::regenerate();                 // unconditional
SessionRegenerator::regenerateIfStale(1800);       // only if 30 minutes have passed
```

`ez-php/auth` already regenerates the id on login/logout itself — `SessionRegenerator` is for periodic regeneration of a long-lived session, independent of authentication.

---

## License

MIT
