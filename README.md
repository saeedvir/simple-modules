# simple-modules
A simple structure for modularizing a Laravel project.

# add `ModuleServiceProvider.php` to `bootstrap\providers.php`
```
use App\Providers\ModuleServiceProvider;
return [
    //other
    ModuleServiceProvider::class,
];
```

# Modular Architecture

Pure Laravel modular system (no third-party packages). Modules live in `modules/` and are auto-discovered by `ModuleServiceProvider`.

## Files

| File | Purpose |
|------|---------|
| `app/Providers/ModuleServiceProvider.php` | Auto-discovers, registers, and boots all modules |
| `app/Console/Commands/MakeModuleCommand.php` | `make:module` Artisan command |
| `bootstrap/providers.php` | Registers `ModuleServiceProvider` |
| `modules/` | Root directory for all modules |

---

## Module Structure

Every module follows this directory layout:

```
modules/{ModuleName}/
├── Console/
│   └── Commands/           # Artisan commands
├── Enums/                  # PHP 8.1 backed enums
├── Helpers/                # Module helper functions
├── Http/
│   ├── Controllers/        # Traditional controllers
│   ├── Middleware/          # HTTP middleware
│   └── Requests/           # Form request validation
├── Jobs/                   # Queued jobs
├── Livewire/               # Livewire components (auto-registered)
├── Models/                 # Eloquent models
├── Observers/              # Model observers
├── Providers/              # Module service provider
├── Services/               # Business logic services
├── config/
│   └── {module}.php        # Module config (merged into app config)
├── database/
│   └── migrations/         # Module migrations (auto-loaded)
├── lang/
│   ├── fa/
│   │   └── {module}.php      # Persian translations (group file)
│   └── en/
│       └── {module}.php      # English translations (group file)
├── resources/
│   └── views/              # Blade views
└── routes/
    ├── web.php             # Admin panel routes (auto-loaded)
    ├── api.php             # API routes (auto-loaded)
    └── frontend.php        # Public frontend routes (auto-loaded)
```

---

## Autoloading

PSR-4 namespaces are registered directly via Composer's autoloader during `ModuleServiceProvider::register()`:

| Namespace | Maps To |
|-----------|---------|
| `Modules\{Name}\` | `modules/{Name}/` |
| `Modules\{Name}\Console\Commands\` | `modules/{Name}/Console/Commands/` |
| `Modules\{Name}\Enums\` | `modules/{Name}/Enums/` |
| `Modules\{Name}\Helpers\` | `modules/{Name}/Helpers/` |
| `Modules\{Name}\Http\Controllers\` | `modules/{Name}/Http/Controllers/` |
| `Modules\{Name}\Http\Middleware\` | `modules/{Name}/Http/Middleware/` |
| `Modules\{Name}\Http\Requests\` | `modules/{Name}/Http/Requests/` |
| `Modules\{Name}\Jobs\` | `modules/{Name}/Jobs/` |
| `Modules\{Name}\Livewire\` | `modules/{Name}/Livewire/` |
| `Modules\{Name}\Models\` | `modules/{Name}/Models/` |
| `Modules\{Name}\Observers\` | `modules/{Name}/Observers/` |
| `Modules\{Name}\Providers\` | `modules/{Name}/Providers/` |
| `Modules\{Name}\Services\` | `modules/{Name}/Services/` |

---

## Auto-Registration

`ModuleServiceProvider` handles all registration automatically:

### register() Phase
1. **PSR-4 autoloading** - Module namespaces registered via Composer autoloader
2. **Config** - Merges `config/{module}.php` into app config (key = lowercase module name)
3. **Migrations** - Loads `database/migrations/` via `loadMigrationsFrom()`
4. **Lang** - Loads `lang/` translations (namespace = lowercase module name)
5. **Views** - Loads `resources/views/` (namespace = lowercase module name)

### boot() Phase
1. **Routes** - Loads `routes/web.php`, `routes/api.php`, `routes/frontend.php`
2. **Livewire** - Scans `Livewire/` recursively and auto-registers all components
3. **Module Provider** - Boots `{Name}ServiceProvider` if it exists

### Route Middleware

| Route File | Middleware Applied |
|------------|-------------------|
| `routes/web.php` | `web` |
| `routes/api.php` | `api`, `auth:sanctum` + prefix `api` |
| `routes/frontend.php` | `web` |

### Livewire Aliases

Components are auto-registered with kebab-case aliases:

| Class | Alias |
|-------|-------|
| `Modules\Shop\Livewire\ProductList` | `shop.product-list` |
| `Modules\Shop\Livewire\Cart\CartWidget` | `shop.cart.cart-widget` |

---

## Creating a Module

### Via Artisan Command

```bash
# Full module with everything
php artisan make:module Blog --all --provider

# Only directory structure
php artisan make:module Blog

# Selective generation
php artisan make:module Shop --model --livewire --migration --config --route --provider
```

### Command Options

| Flag | Generates |
|------|-----------|
| `--all` | All sub-components below |
| `--model` | Eloquent model |
| `--livewire` | Livewire component (interactive: asks for name) |
| `--migration` | Migration file (interactive: asks for table name) |
| `--config` | Config file |
| `--route` | Route files (web, api, frontend) |
| `--lang` | Language files (fa, en) |
| `--view` | View directory with layout |
| `--service` | Service class |
| `--controller` | Controller class |
| `--enum` | Enum class (interactive: asks for name) |
| `--provider` | Module service provider |

### Interactive Example

```bash
$ php artisan make:module Shop --all --provider

 Livewire component name: ProductList
 Table name for migration: products
 Enum name (e.g., Status): Status

  Model [Shop] created.
  Livewire component [ProductList] created.
  Migration [products] created.
  Config [shop.php] created.
  Route files created.
  Language files created.
  View files created.
  Service [ShopService] created.
  Controller [ShopController] created.
  Enum [Status] created.
  Provider [ShopServiceProvider] created.

Module [Shop] created successfully!
```

### Manual Creation

Create the directory structure manually, then add a `composer.json` if you want custom autoloading (optional -- the ModuleServiceProvider handles it).

---

## Module Service Provider

Each module can have its own provider at `Providers/{Name}ServiceProvider.php`. It is auto-instantiated and both `register()` and `boot()` are called by `ModuleServiceProvider`.

```php
<?php

namespace Modules\Shop\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind singletons, register services
        $this->app->singleton(ShopService::class, function ($app) {
            return new ShopService;
        });
    }

    public function boot(): void
    {
        // Register observers, event listeners, middleware, etc.
        Product::observe(ProductObserver::class);
    }
}
```

---

## Using Module Components

### Models

```php
use Modules\Shop\Models\Product;

$products = Product::where('is_active', true)->get();
```

### Livewire Components

In Blade templates (view namespace = lowercase module name):

```blade
{{-- Using Livewire tag --}}
<livewire:shop.product-list />

{{-- Or using @livewire directive --}}
@livewire('shop.product-list')
```

In routes:

```php
use Modules\Shop\Livewire\ProductList;

Route::get('/products', ProductList::class)->name('shop.products');
```

### Config

```php
// Access via module config key (lowercase module name)
$value = config('shop.enabled');
```

### Translations

```php
// Namespace = lowercase module name, group = module name file
// Lang files: lang/{locale}/{module}.php
echo __('shop::shop.name');       // 'Shop'
echo __('shop::shop.welcome', ['name' => $name]);
```

### Views

```blade
{{-- Namespace = lowercase module name --}}
@include('shop::components.layout')

{{-- Or in Livewire components --}}
return view('shop::product-list');
```

---

## Performance

- **Static cache**: `ModuleServiceProvider::$moduleCache` stores discovered modules in memory (resets per request)
- **Config cached**: If `php artisan config:cache` is used, the entire `ModuleServiceProvider` is skipped (modules must be registered via `config/app.php` providers instead)
- **No filesystem scan on boot**: Modules are discovered once in `register()`, reused in `boot()`
- **Composer autoloader**: PSR-4 registration via Composer is O(1) lookups at runtime

---

## Adding an Existing Module to a New Installation

1. Copy the module folder into `modules/`
2. Run `php artisan migrate` (new migrations are auto-loaded)
3. That's it -- `ModuleServiceProvider` auto-discovers it on next request

---

## Observer Auto-Registration

Modules can have model observers in `Observers/` that are auto-registered. Convention: `ProductObserver` observes `Product` model from the same module's `Models/` directory.

```
modules/Shop/
├── Models/
│   └── Product.php
└── Observers/
    └── ProductObserver.php    ← auto-registered on Product
```

**File:** `modules/Shop/Observers/ProductObserver.php`

```php
<?php

namespace Modules\Shop\Observers;

use Modules\Shop\Models\Product;

class ProductObserver
{
    public function created(Product $product): void
    {
        // Fires after Product is created
    }

    public function updated(Product $product): void
    {
        // Fires after Product is updated
    }
}
```

**Rules:**
- Observer file must be in `modules/{Name}/Observers/`
- Observer class name must match `{ModelName}Observer`
- Model must exist in `modules/{Name}/Models/{ModelName}.php`
- Both classes must be autoloaded (PSR-4)

---

## Enabling/Disabling Modules

Disable modules without deleting folders via `config/modules.php` or Artisan commands.

**Config:** `config/modules.php`

```php
return [
    'disabled' => [
        'Blog',
        // 'Shop',
    ],
];
```

**Commands:**

```bash
php artisan module:disable Shop
php artisan module:enable Shop
php artisan config:clear
```

**Effects of disabling:**
- PSR-4 autoloading skipped
- Config not merged
- Migrations not loaded
- Translations not loaded
- Views not registered
- Routes not loaded
- Livewire components not registered
- Observers not registered
- Module ServiceProvider not booted

---

## Quick Reference

| Action | Command / Method |
|--------|-----------------|
| Create module | `php artisan make:module {Name} --all --provider` |
| Enable module | `php artisan module:enable {Name}` |
| Disable module | `php artisan module:disable {Name}` |
| Clear module cache | `ModuleServiceProvider::clearCache()` |
| List discovered modules | `ModuleServiceProvider::getAllModules()` |
| Access module config | `config('{module}.key')` |
| Use module translation | `__('module::module.key')` |
| Reference module view | `view('module::view-name')` |
