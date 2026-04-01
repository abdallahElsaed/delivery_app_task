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

Starts the server, queue worker, log viewer (Pail), and Vite dev server in parallel:

```bash
composer dev
```

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

A **Strategy Pattern**-based import system designed for large-scale product ingestion.

#### Architecture

```
Artisan Command
    │
    ▼
ProductImportProcessor (orchestrator)
    │
    ├── ImporterFactory ──> selects strategy by file extension
    │       │
    │       ├── CsvImportStrategy
    │       └── (future: XlsxImportStrategy, etc.)
    │
    ├── Creates ImportBatch record
    ├── Parses file via strategy (streaming, memory-efficient)
    ├── Chunks rows (50 per chunk)
    └── Dispatches ProcessProductImportChunk jobs
            │
            ▼
        Redis Queue: "imports"
            │
            ▼
        Processes each chunk
        ├── Success: increments batch counter
        └── Failure: creates ImportBatchRow with error details
```

#### Import Commands

```bash
# Import products from a CSV file
php artisan import:products /path/to/products.csv

# Retry failed rows from a previous import
php artisan import:retry {batchId}
```

#### CSV Format

| Column           | Description                                              |
|------------------|----------------------------------------------------------|
| `product_sku`    | Unique product identifier                                |
| `product_name`   | Product display name                                     |
| `product_status` | Product status                                           |
| `currency`       | Price currency code                                      |
| `variant_sku`    | Unique variant identifier                                |
| `variant_name`   | Variant display name                                     |
| `variant_price`  | Variant price                                            |
| `variant_stock`  | Available stock quantity                                 |
| `attributes`     | Pipe-separated key:value pairs (e.g., `Color:Red\|Size:M`) |

#### Tracking Models

- **ImportBatch** -- Tracks overall import progress (status, total/processed/failed counts, timestamps)
- **ImportBatchRow** -- Records individual row failures (row number, raw data, error message)

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

---

### Admin Panel

Built with **Filament v5**.

- **URL:** `/admin`
- **Auth:** Standard User model with session-based authentication
- **Theme:** Amber primary color
- **Resources:** ImportBatch (read-only monitoring of import jobs)

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
