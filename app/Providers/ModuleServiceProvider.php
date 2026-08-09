<?php

namespace App\Providers;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Cache of discovered modules (name => path).
     *
     * @var array<string, string>
     */
    protected static array $moduleCache = [];

    /**
     * Observers already registered, keyed by "ModelClass@ObserverClass".
     *
     * @var array<string, bool>
     */
    protected static array $registeredObservers = [];

    /**
     * Filesystem instance (shared).
     */
    protected Filesystem $filesystem;

    /**
     * The Composer autoloader instance.
     *
     * @var ClassLoader
     */
    protected $autoloader;

    public function register(): void
    {
        // Merge the core modules configuration
        $this->mergeConfigFrom(
            base_path('config/modules.php'),
            'modules'
        );

        $this->filesystem = $this->app['files'];

        $modulesPath = $this->getModulesPath();

        if (! is_dir($modulesPath)) {
            return;
        }

        $modules = $this->discoverModules($modulesPath);

        foreach ($modules as $moduleName => $modulePath) {
            if (! $this->isModuleEnabled($moduleName)) {
                continue;
            }

            $this->registerModuleAutoloading($moduleName, $modulePath);
            $this->registerModuleConfig($moduleName, $modulePath);
            $this->registerModuleMigrations($modulePath);
            $this->registerModuleLang($moduleName, $modulePath);
            $this->registerModuleViews($moduleName, $modulePath);

            // Register the module's own service provider so it can
            // hook into the container and auto‑boot later.
            $this->registerModuleProvider($moduleName, $modulePath);
        }
    }

    public function boot(): void
    {
        $modulesPath = $this->getModulesPath();

        if (! is_dir($modulesPath)) {
            return;
        }

        $modules = $this->discoverModules($modulesPath);

        foreach ($modules as $moduleName => $modulePath) {
            if (! $this->isModuleEnabled($moduleName)) {
                continue;
            }

            $this->registerModuleRoutes($modulePath);
            $this->registerModuleLivewireComponents($moduleName, $modulePath);
            $this->registerModuleObservers($moduleName, $modulePath);
            $this->registerModuleCommands($moduleName, $modulePath);
        }
    }

    // -----------------------------------------------------------------
    //  Discovery & Configuration
    // -----------------------------------------------------------------

    protected function getModulesPath(): string
    {
        return base_path('modules');
    }

    protected function isModuleEnabled(string $moduleName): bool
    {
        $disabled = config('modules.disabled', []);

        return ! in_array($moduleName, $disabled);
    }

    protected function discoverModules(string $modulesPath): array
    {
        if (! empty(self::$moduleCache)) {
            return self::$moduleCache;
        }

        $directories = $this->filesystem->directories($modulesPath);
        $modules = [];

        foreach ($directories as $directory) {
            $moduleName = basename($directory);
            $modules[$moduleName] = $directory;
        }

        self::$moduleCache = $modules;

        return $modules;
    }

    // -----------------------------------------------------------------
    //  Autoloading
    // -----------------------------------------------------------------

    protected function registerModuleAutoloading(string $moduleName, string $modulePath): void
    {
        $namespace = "Modules\\{$moduleName}";
        $autoloader = $this->getAutoloader();

        // Map the root namespace to the module directory
        $autoloader->addPsr4($namespace . '\\', $modulePath . DIRECTORY_SEPARATOR);

        // Dynamically scan all first‑level subdirectories that are not
        // special infrastructure folders and add them as PSR‑4 prefixes.
        $this->registerModuleSubNamespaces($moduleName, $modulePath, $autoloader);
    }

    /**
     * Dynamically add PSR‑4 mappings for all source folders inside a module.
     */
    protected function registerModuleSubNamespaces(
        string $moduleName,
        string $modulePath,
        $autoloader
    ): void {
        $namespace = "Modules\\{$moduleName}";

        // Folders that should NOT be mapped as separate namespaces
        $excluded = [
            'config',
            'database',
            'routes',
            'resources',
            'lang',
            'tests',
            '.git',
            'vendor',
        ];

        $subDirs = $this->filesystem->directories($modulePath);

        foreach ($subDirs as $subDir) {
            $folderName = basename($subDir);

            if (in_array($folderName, $excluded)) {
                continue;
            }

            // Convert folder name to namespace (e.g. "Http/Controllers" → "Http\Controllers")
            $relativePath = str_replace($modulePath . DIRECTORY_SEPARATOR, '', $subDir);
            $subNamespace = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            $autoloader->addPsr4(
                $namespace . '\\' . $subNamespace . '\\',
                $subDir . DIRECTORY_SEPARATOR
            );
        }
    }

    /**
     * Return the Composer autoloader (singleton).
     */
    protected function getAutoloader(): ClassLoader
    {
        if (! isset($this->autoloader)) {
            $this->autoloader = require base_path('vendor/autoload.php');
        }

        return $this->autoloader;
    }

    // -----------------------------------------------------------------
    //  Config, Migrations, Lang, Views
    // -----------------------------------------------------------------

    protected function registerModuleConfig(string $moduleName, string $modulePath): void
    {
        // Only merge when config is NOT cached; the cached file already
        // contains all merged values.
        if ($this->app->configurationIsCached()) {
            return;
        }

        $lowerModuleName = Str::lower($moduleName);
        $configPath = $modulePath . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . $lowerModuleName . '.php';

        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, $lowerModuleName);
        }
    }

    protected function registerModuleMigrations(string $modulePath): void
    {
        $migrationPath = $modulePath . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'migrations';

        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }
    }

    protected function registerModuleLang(string $moduleName, string $modulePath): void
    {
        $langPath = $modulePath . DIRECTORY_SEPARATOR . 'lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, Str::lower($moduleName));
        }
    }

    protected function registerModuleViews(string $moduleName, string $modulePath): void
    {
        $viewsPath = $modulePath . DIRECTORY_SEPARATOR . 'resources'
            . DIRECTORY_SEPARATOR . 'views';

        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, Str::lower($moduleName));
        }
    }

    protected function registerModuleCommands(string $moduleName, string $modulePath): void
    {
        $commandsPath = $modulePath . DIRECTORY_SEPARATOR . 'Console'
            . DIRECTORY_SEPARATOR . 'Commands';

        if (! is_dir($commandsPath)) {
            return;
        }

        $namespace = "Modules\\{$moduleName}\\Console\\Commands";
        $classes = [];

        $this->collectCommandClasses($commandsPath, $namespace, $classes);

        foreach ($classes as $className) {
            if (class_exists($className)) {
                $this->commands($className);
            }
        }
    }

    /**
     * Recursively collect fully‑qualified class names of command files.
     */
    protected function collectCommandClasses(string $directory, string $namespace, array &$classes): void
    {
        $files = $this->filesystem->files($directory);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $classes[] = $namespace . '\\' . $file->getBasename('.php');
        }

        $subDirs = $this->filesystem->directories($directory);
        foreach ($subDirs as $subDir) {
            $subNamespace = $namespace . '\\' . basename($subDir);
            $this->collectCommandClasses($subDir, $subNamespace, $classes);
        }
    }

    // -----------------------------------------------------------------
    //  Routes
    // -----------------------------------------------------------------

    protected function registerModuleRoutes(string $modulePath): void
    {
        if ($this->app->routesAreCached()) {
            return;   // All routes are already in the cache
        }

        $routesDir = $modulePath . DIRECTORY_SEPARATOR . 'routes';

        if (! is_dir($routesDir)) {
            return;
        }

        // Web routes
        $webRoutes = $routesDir . DIRECTORY_SEPARATOR . 'web.php';
        if (file_exists($webRoutes)) {
            $this->app['router']
                ->middleware(['web'])
                ->group($webRoutes);
        }

        // API routes – middleware group is configurable; no forced prefix
        $apiRoutes = $routesDir . DIRECTORY_SEPARATOR . 'api.php';
        if (file_exists($apiRoutes)) {
            $apiMiddleware = config('modules.api_middleware', ['api']);
            $this->app['router']
                ->middleware($apiMiddleware)
                ->group($apiRoutes);
        }

        // Legacy frontend routes
        $frontendRoutes = $routesDir . DIRECTORY_SEPARATOR . 'frontend.php';
        if (file_exists($frontendRoutes)) {
            $this->app['router']
                ->middleware(['web'])
                ->group($frontendRoutes);
        }

        // AI / MCP routes – now properly secured with configurable middleware
        $aiRoutes = $routesDir . DIRECTORY_SEPARATOR . 'ai.php';
        if (file_exists($aiRoutes)) {
            $this->app['router']
                ->middleware(config('modules.ai_middleware', ['api']))
                ->group($aiRoutes);
        }
    }

    // -----------------------------------------------------------------
    //  Livewire Components
    // -----------------------------------------------------------------

    protected function registerModuleLivewireComponents(string $moduleName, string $modulePath): void
    {
        $livewirePath = $modulePath . DIRECTORY_SEPARATOR . 'Livewire';

        if (! is_dir($livewirePath)) {
            return;
        }

        $this->scanAndRegisterLivewireComponents(
            $livewirePath,
            "Modules\\{$moduleName}\\Livewire"
        );
    }

    protected function scanAndRegisterLivewireComponents(
        string $directory,
        string $namespace
    ): void {
        $files = $this->filesystem->files($directory);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $namespace . '\\' . $file->getFilenameWithoutExtension();

            if (! class_exists($className)) {
                continue;
            }

            if ($this->isLivewireComponent($className)) {
                $alias = $this->generateLivewireAlias($className);
                Livewire::component($alias, $className);
            }
        }

        // Recurse into subdirectories
        $directories = $this->filesystem->directories($directory);
        foreach ($directories as $subDir) {
            $subNamespace = $namespace . '\\' . basename($subDir);
            $this->scanAndRegisterLivewireComponents($subDir, $subNamespace);
        }
    }

    protected function isLivewireComponent(string $className): bool
    {
        return is_subclass_of($className, Component::class);
    }

    protected function generateLivewireAlias(string $className): string
    {
        $parts = explode('\\', $className);

        // Safely locate the module segment
        $moduleIndex = array_search('Modules', $parts);
        if ($moduleIndex === false) {
            // Fallback: use the last part of the class name
            return Str::kebab(end($parts));
        }

        $moduleName = Str::kebab($parts[$moduleIndex + 1] ?? '');

        $livewireIndex = array_search('Livewire', $parts);
        if ($livewireIndex === false) {
            // Should not happen, but fallback
            $componentParts = array_map(fn($p) => Str::kebab($p), array_slice($parts, $moduleIndex + 2));
        } else {
            $componentParts = array_map(
                fn($p) => Str::kebab($p),
                array_slice($parts, $livewireIndex + 1)
            );
        }

        return implode('.', array_filter([$moduleName, ...$componentParts]));
    }

    // -----------------------------------------------------------------
    //  Observers
    // -----------------------------------------------------------------

    protected function registerModuleObservers(string $moduleName, string $modulePath): void
    {
        $observersPath = $modulePath . DIRECTORY_SEPARATOR . 'Observers';

        if (! is_dir($observersPath)) {
            return;
        }

        $namespace = "Modules\\{$moduleName}";
        $files = $this->filesystem->files($observersPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $observerName = $file->getFilenameWithoutExtension();
            $modelName = Str::replaceLast('Observer', '', $observerName);

            // Skip if we couldn't derive a model name (e.g. file named just "Observer.php")
            if (empty($modelName)) {
                continue;
            }

            $observerClass = "{$namespace}\\Observers\\{$observerName}";
            $modelClass = "{$namespace}\\Models\\{$modelName}";

            if (
                class_exists($observerClass) &&
                class_exists($modelClass)
            ) {
                $key = $modelClass . '@' . $observerClass;

                // Prevent duplicate registration (especially in long‑running processes)
                if (! isset(self::$registeredObservers[$key])) {
                    $modelClass::observe($observerClass);
                    self::$registeredObservers[$key] = true;
                }
            }
        }
    }

    // -----------------------------------------------------------------
    //  Module’s own Service Provider
    // -----------------------------------------------------------------

    /**
     * Register the module’s custom ServiceProvider using Laravel’s
     * standard container registration so that register() runs immediately
     * and boot() is called automatically.
     */
    protected function registerModuleProvider(string $moduleName, string $modulePath): void
    {
        $namespace = "Modules\\{$moduleName}";
        $providerClass = "{$namespace}\\Providers\\{$moduleName}ServiceProvider";

        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }

    // -----------------------------------------------------------------
    //  Cache helpers (public API)
    // -----------------------------------------------------------------

    public static function getCachedModules(): array
    {
        return self::$moduleCache;
    }

    public static function clearCache(): void
    {
        self::$moduleCache = [];
    }

    public static function getAllModules(): array
    {
        $modulesPath = base_path('modules');

        if (! is_dir($modulesPath)) {
            return [];
        }

        $filesystem = app('files');
        $directories = $filesystem->directories($modulesPath);
        $modules = [];

        foreach ($directories as $directory) {
            $modules[] = basename($directory);
        }

        return $modules;
    }
}
