# Delivery App

A Laravel 12 delivery application featuring multi-guard authentication with passwordless OTP login, a scalable product import system, real-time notifications via Twilio, and a Filament admin panel for monitoring.

---

## Table of Contents

- [Requirements](#requirements)
- [Setup](#setup)
- [Running the Application](#running-the-application)
- [Testing](#testing)
- [Code Quality](#code-quality)
- [Architecture](#architecture)
  - [Directory Structure](#directory-structure)
  - [Authentication System](#authentication-system)
  - [Product Domain](#product-domain)
  - [CSV Import System](#csv-import-system)
  - [Notification System](#notification-system)
  - [Queue Configuration](#queue-configuration)
  - [Admin Panel](#admin-panel)
  - [Logging](#logging)
- [Architectural Patterns](#architectural-patterns)
- [API Reference](#api-reference)
- [Extending the Application](#extending-the-application)

---

## Requirements

- PHP >= 8.2
- Composer
- MySQL
- Redis
- Twilio account (for SMS/WhatsApp notifications)

## Setup

### 1. Clone the repository

```bash
git clone <repository-url>
cd delivery_app_task
```

### 2. Environment configuration

```bash
cp .env.example .env
```

Update the `.env` file with your configuration:

```dotenv
# MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=delivery_app
DB_USERNAME=root
DB_PASSWORD=your_password

# Redis (used for queue and caching)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

QUEUE_CONNECTION=redis
CACHE_STORE=redis

# Rows per import chunk job (default 500)
IMPORT_CHUNK_SIZE=500

# Twilio
TWILIO_SID=your_twilio_sid
TWILIO_AUTH_TOKEN=your_twilio_auth_token
TWILIO_PHONE_NUMBER=your_twilio_phone_number
TWILIO_WHATSAPP_NUMBER=your_twilio_whatsapp_number
```

### 3. Run setup

This installs dependencies, generates app key, runs migrations, and builds assets:

```bash
composer setup
```

---

## Running the Application

Starts the server, a queue worker for the `imports`, `notification` and `default` queues, the log viewer (Pail), and the Vite dev server in parallel:

```bash
composer dev
```

---

## Testing

```bash
composer test
```

Tests run on SQLite in-memory with the `sync` queue (see `phpunit.xml`). Any PHP deprecation fails the run.

---

## Code Quality

```bash
composer check
```

Runs Pint (formatting), Larastan level 5 (static analysis) and the test suite. `phpstan-baseline.neon` lists errors in code that predates Larastan. GitHub Actions runs `composer check` on every push and pull request.

---

## Architecture

### Directory Structure

```
app/
├── Actions/Auth/             # Single-responsibility auth actions
│   ├── LoginAction            # Generate & send OTP
│   ├── CheckOtpAction         # Validate OTP & issue Sanctum token
│   └── OtpRateLimiter         # Rate limiting for OTP attempts
├── Console/Commands/Import/   # Artisan import commands
├── Contracts/                 # Interfaces & contracts
│   ├── Auth/                  # HasMobileLogin contract
│   ├── Imports/               # ImporterInterface
│   └── Notification/          # Notification contracts
├── Enums/Import/              # Import status enums
├── Filament/Resources/        # Admin panel resources
├── Http/
│   ├── Controllers/Auth/      # Auth controllers (Customer & Driver)
│   ├── Middleware/             # Custom middleware
│   └── Requests/Auth/         # Form request validation
├── Jobs/
│   ├── Import/                # Product import chunk processing jobs
│   └── Notification/          # SMS & WhatsApp notification jobs
├── Models/                    # Eloquent models
├── Providers/                 # Service providers
├── Service/Imports/           # Import service & strategies
│   └── Strategies/            # CSV/JSON import strategies
└── Traits/                    # Shared traits (ResponseJsonTrait)
```

---

### Authentication System

The application implements **three separate authentication guards**:

| Guard    | Type           | Use Case                |
|----------|----------------|-------------------------|
| Admin    | Session (web)  | Filament admin panel    |
| Customer | Sanctum (API)  | Mobile app - customers  |
| Driver   | Sanctum (API)  | Mobile app - drivers    |

#### OTP Authentication Flow

Both Customer and Driver use **passwordless mobile authentication**:

```
Mobile App                    Server                         Twilio
    │                           │                              │
    │── POST /login ───────────>│                              │
    │   {mobile}                │── Generate 4-digit OTP       │
    │                           │── Store in Redis (5min TTL)  │
    │                           │── Send OTP ─────────────────>│
    │                           │   (SMS + WhatsApp)           │
    │<── {message: "OTP sent"} ─│                              │
    │                           │                              │
    │── POST /check-otp ───────>│                              │
    │   {mobile, otp}           │── Validate OTP from Redis    │
    │                           │── Check rate limit (3/5min)  │
    │                           │── Issue Sanctum token        │
    │<── {token, user} ────────│                              │
```

**Key components:**
- `LoginAction` -- Generates OTP, dispatches SMS & WhatsApp jobs
- `CheckOtpAction` -- Validates OTP, creates/finds user, issues token
- `OtpRateLimiter` -- Enforces 3 attempts per 5 minutes
- `HasMobileLogin` -- Contract shared by Customer & Driver models

---

### Product Domain

#### Model Hierarchy

```
Product (name, sku, status, currency)
  └── ProductVariant (name, sku, price, stock)
        └── VariantAttribute (name, value)
```

- **Product** -- The top-level catalog item (e.g., "T-Shirt")
- **ProductVariant** -- A purchasable variation (e.g., "Red / Large")
- **VariantAttribute** -- Key-value descriptors (e.g., `Color:Red`, `Size:L`)

> `product_id` exists on both `ProductVariant` and `VariantAttribute` for query optimization (denormalized for performance).

---

### CSV Import System

A **Strategy Pattern**-based import system for large product catalogues, in CSV or JSON.

#### Architecture

```
import:products command        Admin upload (/admin)
(copies the file)              (stores the file)
        │                              │
        └──────────────┬───────────────┘
                       ▼
     storage/app/private/imports/<ulid>/<file name>
                       │
                       ▼
ProductImportProcessor ── creates ImportBatch (pending)
                       │
                       ▼
ReadProductImportFile job (queue "imports")
    ├── ImporterFactory ──> CsvImportStrategy / JsonImportStrategy
    ├── File-level error (format, headers, empty file) ──> batch failed + error_message
    ├── Records malformed rows and in-file conflicts as failed rows
    ├── Sets total_rows and marks the batch processing
    └── Adds ProcessProductImportChunk jobs (IMPORT_CHUNK_SIZE rows each) to the same job batch
            │
            ▼
        ProcessProductImportChunk (queue "imports")
        ├── Validates each row (ProductImportRowValidator)
        ├── Saves product, variant and attributes in one transaction per row
        └── Records failed rows with line number, reason and retryable flag
            │
            ▼
        Batch becomes completed once every chunk has finished
```

Nothing is parsed in the command or the HTTP request: both only store the file and queue the reading job.

#### Import Commands

```bash
# Import products from a CSV or JSON file
php artisan import:products /path/to/products.csv

# Retry the retryable failed rows of a finished import
php artisan import:retry {batchId}
```

The admin upload accepts `.csv` and `.json` files up to 50 MB.

#### Try it locally (included sample files)

This repo includes sample import files you can use right away:

```bash
# 100k rows CSV
php artisan import:products products_test_100k.csv

# JSON sample
php artisan import:products products_test.json
```

#### Postman collection

A Postman collection is included in the repo root:

- `Delivery App.postman_collection.json`

#### Import benchmark (100,000 rows)

Importing `products_test_100k.csv` on local MySQL, 500-row chunks unless noted:

| Run | Workers | Time | Rows/sec |
|-----|---------|------|----------|
| Before the import hardening: first import | 1 | ~29.5 min | ~56 |
| Before the import hardening: re-import (2000-row chunks) | 1 | ~62 min | ~27 |
| After: re-import | 1 (`sync` queue) | 91 s | ~1,100 |
| After: re-import with changed attributes | 4 Redis workers | 60 s | ~1,670 |

On the same single worker, a re-import went from ~62 min to 91 s. A first import into an empty database was not re-measured after the change.

#### CSV Format

The header row is matched ignoring case, surrounding spaces, column order and a byte-order mark. JSON files are a list of objects with the same keys.

| Column           | Rule                                                        |
|------------------|-------------------------------------------------------------|
| `product_sku`    | Required, up to 255 characters; repeated on each row of the same product |
| `product_name`   | Required, up to 255 characters                              |
| `product_status` | `active`, `inactive` or `draft`                             |
| `currency`       | 3 uppercase letters (e.g. `USD`)                            |
| `variant_sku`    | Required, up to 255 characters; one row per variant          |
| `variant_name`   | Required, up to 255 characters                              |
| `variant_price`  | 0 to 99,999,999.99, at most 2 decimal places                |
| `variant_stock`  | Whole number from 0 to 2,147,483,647                        |
| `attributes`     | Optional `key:value` pairs separated by `\|` (e.g., `Color:Red\|Size:M`) |

#### Import Rules

- A row that breaks a rule fails with every reason listed, and nothing from it is saved.
- Each row is saved in one transaction: product, variant and attributes together or not at all.
- Importing the same file twice gives the same catalogue as importing it once. Product and variant SKUs are unique across the catalogue.
- A variant's attributes are **replaced** by the row's attributes; an empty `attributes` cell removes them all. Attribute keys can't repeat within a row (compared ignoring case).
- A variant never moves between products: a row whose `variant_sku` belongs to another product fails.
- Within one file the first row wins: a later row repeating a `variant_sku`, or giving different name, status or currency for the same product, fails and can't be retried.
- Across separate imports the latest file wins. Products and variants missing from a file are left untouched.

#### Tracking Models

- **ImportBatch** -- Tracks an import: status (`pending` → `processing` → `completed`, or `failed` for a file-level error with its `error_message`), total/processed/failed counts and timestamps
- **ImportBatchRow** -- Records a failed row: its line in the file, raw data, reason, status (`failed`, `retrying`, `resolved`) and whether it can be retried

#### Retry

`import:retry` and the admin **Retry** button share the same rules: they are refused while the batch is `pending` or `processing`, retry only rows marked retryable, process them in chunks, and move each row to `resolved` or back to `failed` with its new reason. The batch returns to the status it had before the retry.

---

### Notification System

#### Twilio Integration

| Channel  | Job Class                 | Queue          |
|----------|---------------------------|----------------|
| SMS      | `SmsNotificationJop`      | `notification` |
| WhatsApp | `WhatsAppNotificationJop` | `notification` |

Both jobs are queued via Redis and processed asynchronously. The Twilio client is registered as a singleton in `AppServiceProvider`.

---

### Queue Configuration

**Driver:** Redis

| Queue          | Purpose                          |
|----------------|----------------------------------|
| `imports`      | Product import chunk processing  |
| `notification` | SMS & WhatsApp message delivery  |
| `default`      | General-purpose jobs             |

A worker must name the queues it serves, or it only works `default`:

```bash
php artisan queue:work redis --queue=imports,notification,default
```

---

### Admin Panel

Built with **Filament v5**.

- **URL:** `/admin`
- **Auth:** Standard User model with session-based authentication
- **Theme:** Amber primary color
- **Resources:** ImportBatch (upload import files, monitor progress and failed rows, retry failed rows)

Resources are auto-discovered from `app/Filament/Resources/`.

---

### Logging

Custom log channels write to separate files for subsystem debugging:

| Channel        | File                            | Tracks                               |
|----------------|---------------------------------|--------------------------------------|
| `auth`         | `storage/logs/auth.log`         | OTP generation, validation, rate limits |
| `notification` | `storage/logs/notification.log` | SMS/WhatsApp delivery success/failure |
| `import`       | `storage/logs/import.log`       | Product import processing            |

---

## Architectural Patterns

### Action Classes

Single-responsibility classes in `app/Actions/` encapsulate business logic away from controllers:

- `LoginAction` -- Generate OTP and dispatch notification jobs
- `CheckOtpAction` -- Validate OTP and issue authentication token
- `OtpRateLimiter` -- Enforce rate limits on OTP attempts

### Strategy Pattern

The import system uses the Strategy pattern for file format extensibility. Adding a new format requires only implementing `ImporterInterface` and registering it in `ImporterFactory`.

### Contract-Driven Design

Key interfaces enforce consistency across implementations:

- `HasMobileLogin` -- Ensures Customer and Driver models expose required auth methods
- `ImporterInterface` -- Defines the file parsing contract for import strategies

### Response Standardization

`ResponseJsonTrait` provides a consistent API response format across all endpoints:

```json
{
  "data": {},
  "message": "Success",
  "success": true,
  "extra": {}
}
```

Methods: `successResponse($data, $message, $extra)` and `errorResponse($message, $statusCode)`.

---

## API Reference

### Customer Endpoints

| Method | Endpoint                   | Description        | Auth   |
|--------|----------------------------|--------------------|--------|
| POST   | `/api/customer/login`      | Request OTP        | Public |
| POST   | `/api/customer/check-otp`  | Verify OTP & login | Public |

### Driver Endpoints

| Method | Endpoint                 | Description        | Auth   |
|--------|--------------------------|--------------------|--------|
| POST   | `/api/driver/login`      | Request OTP        | Public |
| POST   | `/api/driver/check-otp`  | Verify OTP & login | Public |
| POST   | `/api/driver/logout`     | Revoke token       | Bearer |

---

## Extending the Application

### Adding a New Authentication Guard

1. Add the guard and provider to `config/auth.php`
2. Create a model implementing `Authenticatable` and `HasApiTokens`
3. If OTP login is needed, implement the `HasMobileLogin` contract
4. Add routes in `routes/api.php` with the appropriate guard middleware

### Adding a New Import Format

1. Create a strategy class implementing `ImporterInterface`
2. Register it in `ImporterFactory::make()` keyed by file extension
3. The existing `ProductImportProcessor` handles the rest automatically

### Adding a New Notification Channel

1. Create a job class extending the base `Job` class
2. Set the queue to `notification` in the constructor
3. Inject the Twilio client from the service container
4. Log to the `notification` channel for debugging
