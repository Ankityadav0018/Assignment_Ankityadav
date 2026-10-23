# policyflow

A Laravel 11 application.

## Requirements

- PHP 8.2+
- Composer
- MySQL 8.0+

## Setup

```bash
# 1. Clone the repository
git clone <repo-url> policyflow
cd policyflow

# 2. Install PHP dependencies
composer install

# 3. Copy env file and fill in your values
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Create the main and test databases in MySQL
#    CREATE DATABASE policyflow;
#    CREATE DATABASE policyflow_test;

# 6. Run migrations
php artisan migrate

# 7. Start the development server
php artisan serve
```

## Testing

PHPUnit is configured to run against a separate `policyflow_test` MySQL database.  
Update `DB_USERNAME` and `DB_PASSWORD` in `phpunit.xml` (or set them as env vars) before running tests.

```bash
php artisan test
```
