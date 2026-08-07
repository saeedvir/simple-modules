<?php

namespace App\Console\Commands\Module;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : The name of the module (PascalCase, e.g., Blog, Shop, Forum)}
        {--all : Generate all sub-components (model, livewire, migration, config, routes, lang, views)}
        {--model : Generate a model for the module}
        {--livewire : Generate a Livewire component}
        {--migration : Generate a migration file}
        {--config : Generate a config file}
        {--route : Generate route files}
        {--lang : Generate language files}
        {--view : Generate a view directory with layout}
        {--service : Generate a service class}
        {--controller : Generate a controller}
        {--enum : Generate an enum}
        {--provider : Generate a service provider for the module}';

    protected $description = 'Generate a new module with directory structure and boilerplate';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        $name = $this->argument('name');

        // --- Validations ------------------------------------------------
        if (! preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Module name must be PascalCase (e.g., Blog, Shop, Forum).');

            return self::FAILURE;
        }

        // Warn if contradictory flags are used
        if ($this->option('all') && (
            $this->option('model') ||
            $this->option('livewire') ||
            $this->option('migration') ||
            $this->option('config') ||
            $this->option('route') ||
            $this->option('lang') ||
            $this->option('view') ||
            $this->option('service') ||
            $this->option('controller') ||
            $this->option('enum') ||
            $this->option('provider')
        )) {
            $this->warn('You used --all together with individual flags. The individual flags will be ignored.');
        }

        $modulePath = base_path('modules/'.$name);

        if (is_dir($modulePath)) {
            $this->error("Module [{$name}] already exists at {$modulePath}");

            return self::FAILURE;
        }

        // --- Start generation with rollback on failure ------------------
        try {
            $this->createModuleStructure($name, $modulePath);

            $generateAll = $this->option('all');

            // Interactive prompts when needed (both --all and individual flags)
            $componentName = $name.'List';
            $enumName = 'Status';

            if ($generateAll || $this->option('livewire')) {
                $componentName = $this->ask('Livewire component name', $name.'List');
            }
            if ($generateAll || $this->option('enum')) {
                $enumName = $this->ask('Enum name (e.g., Status)', 'Status');
            }
            if ($generateAll || $this->option('migration')) {
                $tableName = $this->ask('Table name for migration', Str::snake(Str::plural($name)));
            }

            // Generate components
            if ($generateAll || $this->option('model')) {
                $this->createModel($name, $modulePath);
            }

            if ($generateAll || $this->option('livewire')) {
                $this->createLivewireComponent($name, $modulePath, $componentName);
            }

            if ($generateAll || $this->option('migration')) {
                $this->createMigration($name, $modulePath, $tableName);
            }

            if ($generateAll || $this->option('config')) {
                $this->createConfig($name, $modulePath);
            }

            if ($generateAll || $this->option('route')) {
                $this->createRoutes($name, $modulePath);
            }

            if ($generateAll || $this->option('lang')) {
                $this->createLangFiles($name, $modulePath);
            }

            if ($generateAll || $this->option('view')) {
                $this->createViewFiles($name, $modulePath);
            }

            if ($generateAll || $this->option('service')) {
                $this->createService($name, $modulePath);
            }

            if ($generateAll || $this->option('controller')) {
                $this->createController($name, $modulePath);
            }

            if ($generateAll || $this->option('enum')) {
                $this->createEnum($name, $modulePath, $enumName);
            }

            if ($generateAll || $this->option('provider')) {
                $this->createModuleProvider($name, $modulePath);
            }
        } catch (\Throwable $e) {
            // Rollback: delete the entire module directory if an error occurs
            if (is_dir($modulePath)) {
                $this->files->deleteDirectory($modulePath);
            }
            $this->error('Module creation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        // --- Final output -----------------------------------------------
        $this->newLine();
        $this->info("Module [{$name}] created successfully!");
        $this->newLine();

        $this->line('Directory structure:');
        $this->line("  modules/{$name}/");
        $this->line('    ├── Console/Commands/');
        $this->line('    ├── Enums/');
        $this->line('    ├── Helpers/');
        $this->line('    ├── Http/Controllers/');
        $this->line('    │   ├── Middleware/');
        $this->line('    │   └── Requests/');
        $this->line('    ├── Jobs/');
        $this->line('    ├── Livewire/');
        $this->line('    ├── Models/');
        $this->line('    ├── Observers/');
        $this->line('    ├── Providers/');
        $this->line('    ├── Services/');
        $this->line('    ├── config/');
        $this->line('    ├── database/migrations/');
        $this->line('    ├── lang/');
        $this->line('    ├── resources/views/');
        $this->line('    └── routes/');
        $this->newLine();
        $this->line('Module is auto-discovered by ModuleServiceProvider.');

        return self::SUCCESS;
    }

    // -----------------------------------------------------------------
    //  Directory scaffolding
    // -----------------------------------------------------------------

    protected function createModuleStructure(string $name, string $modulePath): void
    {
        $directories = [
            'Console/Commands',
            'Enums',
            'Helpers',
            'Http/Controllers',
            'Http/Middleware',
            'Http/Requests',
            'Jobs',
            'Livewire',
            'Models',
            'Observers',
            'Providers',
            'Services',
            'config',
            'database/migrations',
            'lang',
            'resources/views',
            'routes',
        ];

        foreach ($directories as $directory) {
            $path = $modulePath.DIRECTORY_SEPARATOR.$directory;
            if (! is_dir($path)) {
                $this->files->makeDirectory($path, recursive: true);
            }
        }

        // Create .gitkeep in empty dirs
        $emptyDirs = ['Console/Commands', 'Enums', 'Helpers', 'Http/Middleware', 'Http/Requests', 'Jobs', 'Observers', 'Services'];
        foreach ($emptyDirs as $dir) {
            $gitkeepPath = $modulePath.DIRECTORY_SEPARATOR.$dir.DIRECTORY_SEPARATOR.'.gitkeep';
            if (! file_exists($gitkeepPath)) {
                $this->files->put($gitkeepPath, '');
            }
        }
    }

    // -----------------------------------------------------------------
    //  File generators
    // -----------------------------------------------------------------

    protected function createModel(string $name, string $modulePath): void
    {
        $modelPath = $modulePath.'/Models/'.$name.'.php';
        $table = Str::snake(Str::plural($name));

        $content = "<?php

namespace Modules\\{$name}\\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {$name} extends Model
{
    use HasFactory;

    protected \$table = '{$table}';

    protected \$fillable = [
        //
    ];

    protected \$casts = [
        //
    ];
}
";

        $this->files->put($modelPath, $content);
        $this->info("  Model [{$name}] created.");
    }

    protected function createLivewireComponent(string $name, string $modulePath, string $componentName): void
    {
        $classPath = $modulePath.'/Livewire/'.$componentName.'.php';
        $lowerName = Str::lower($name);
        $kebabComponent = Str::kebab($componentName);

        // Class file
        $classContent = "<?php

namespace Modules\\{$name}\\Livewire;

use Livewire\\Component;
use Livewire\\WithPagination;

class {$componentName} extends Component
{
    use WithPagination;

    public \$search = '';

    public function render()
    {
        return view('{$lowerName}::{$kebabComponent}');
    }
}
";

        $this->files->put($classPath, $classContent);

        // Corresponding view
        $viewDir = $modulePath.'/resources/views';
        if (! is_dir($viewDir)) {
            $this->files->makeDirectory($viewDir, recursive: true);
        }
        $viewPath = $viewDir.'/'.$kebabComponent.'.blade.php';
        $viewContent = '<div>
    <!-- Livewire component: '.$componentName.' -->
</div>';
        $this->files->put($viewPath, $viewContent);

        $this->info("  Livewire component [{$componentName}] created (class + view).");
    }

    protected function createMigration(string $name, string $modulePath, string $tableName): void
    {
        // Avoid timestamp collisions by adding microseconds
        $timestamp = date('Y_m_d_His').'_'.substr((string) microtime(true) * 1000, -4);
        $migrationPath = $modulePath."/database/migrations/{$timestamp}_create_{$tableName}_table.php";

        $content = "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
";

        $this->files->put($migrationPath, $content);
        $this->info("  Migration [{$tableName}] created.");
    }

    protected function createConfig(string $name, string $modulePath): void
    {
        $lowerName = Str::lower($name);
        $configPath = $modulePath."/config/{$lowerName}.php";

        $content = "<?php

return [
    /*
    |--------------------------------------------------------------------------
    | {$name} Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the {$name} module.
    |
    */

    'enabled' => true,
];
";

        $this->files->put($configPath, $content);
        $this->info("  Config [{$lowerName}.php] created.");
    }

    protected function createRoutes(string $name, string $modulePath): void
    {
        $lowerName = Str::lower($name);

        // Web routes – unopinionated by default
        $webContent = "<?php

use Illuminate\Support\Facades\Route;

// Add your web routes here.
// Route::get('/{$lowerName}', \\Modules\\{$name}\\Livewire\\{$name}List::class)->name('{$lowerName}.index');
";
        $this->files->put($modulePath.'/routes/web.php', $webContent);

        // API routes – use a generic auth:api guard, adjustable by the developer
        $apiContent = "<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api'])->group(function () {
    // Route::get('/{$lowerName}', [\\Modules\\{$name}\\Http\\Controllers\\{$name}Controller::class, 'index']);
});
";
        $this->files->put($modulePath.'/routes/api.php', $apiContent);

        // Frontend routes
        $frontendContent = "<?php

use Illuminate\Support\Facades\Route;

// Public-facing routes
// Route::get('/{$lowerName}', [\\Modules\\{$name}\\Http\\Controllers\\{$name}Controller::class, 'index'])->name('frontend.{$lowerName}.index');
";
        $this->files->put($modulePath.'/routes/frontend.php', $frontendContent);

        $this->info('  Route files created.');
    }

    protected function createLangFiles(string $name, string $modulePath): void
    {
        $langDir = $modulePath.'/lang';
        $lowerName = Str::lower($name);

        // Create locale subdirectories for the application's current locale
        $locale = app()->getLocale();
        $this->files->makeDirectory($langDir.'/'.$locale, recursive: true, force: true);

        $langContent = "<?php

return [
    'name' => '{$name}',
];
";
        $this->files->put($langDir."/{$locale}/{$lowerName}.php", $langContent);

        $this->info("  Language file [{$locale}/{$lowerName}.php] created.");
    }

    protected function createViewFiles(string $name, string $modulePath): void
    {
        $lowerName = Str::lower($name);
        $viewsPath = $modulePath.'/resources/views';

        // Create components directory
        if (! is_dir($viewsPath.'/components')) {
            $this->files->makeDirectory($viewsPath.'/components', recursive: true);
        }

        // A simple layout component
        $layoutContent = '<div>
    {{ $slot }}
</div>
';
        $this->files->put($viewsPath.'/components/layout.blade.php', $layoutContent);

        $this->info('  View files created.');
    }

    protected function createService(string $name, string $modulePath): void
    {
        $servicePath = $modulePath.'/Services/'.$name.'Service.php';

        $content = "<?php

namespace Modules\\{$name}\\Services;

class {$name}Service
{
    //
}
";

        $this->files->put($servicePath, $content);
        $this->info("  Service [{$name}Service] created.");
    }

    protected function createController(string $name, string $modulePath): void
    {
        $controllerPath = $modulePath.'/Http/Controllers/'.$name.'Controller.php';

        $content = "<?php

namespace Modules\\{$name}\\Http\\Controllers;

use Illuminate\Routing\Controller;

class {$name}Controller extends Controller
{
    //
}
";

        $this->files->put($controllerPath, $content);
        $this->info("  Controller [{$name}Controller] created.");
    }

    protected function createEnum(string $name, string $modulePath, string $enumName): void
    {
        $enumPath = $modulePath.'/Enums/'.$enumName.'.php';

        // Generic English labels
        $content = '<?php

namespace Modules\\'.$name.'\\Enums;

enum '.$enumName.': string
{
    case Active = \'active\';
    case Inactive = \'inactive\';

    public function label(): string
    {
        return match ($this) {
            self::Active => \'Active\',
            self::Inactive => \'Inactive\',
        };
    }
}
';

        $this->files->put($enumPath, $content);
        $this->info("  Enum [{$enumName}] created.");
    }

    protected function createModuleProvider(string $name, string $modulePath): void
    {
        $providerPath = $modulePath.'/Providers/'.$name.'ServiceProvider.php';

        $content = "<?php

namespace Modules\\{$name}\\Providers;

use Illuminate\Support\ServiceProvider;

class {$name}ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
";

        $this->files->put($providerPath, $content);
        $this->info("  Provider [{$name}ServiceProvider] created.");
    }
}
