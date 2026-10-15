<?php

namespace App\Console\Commands\Module;

use App\Providers\ModuleServiceProvider;
use App\Support\ModuleLifecycle;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ModuleRemoveCommand extends Command
{
    protected $signature = 'module:remove
        {name : The module name (PascalCase)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Remove a module: run its .init/_remove.php cleanup, then delete the directory';

    public function handle(Filesystem $files): int
    {
        $name = (string) $this->argument('name');

        if (! preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Module name must be PascalCase (e.g., Blog, Shop, Forum).');

            return self::FAILURE;
        }

        if (ModuleLifecycle::isProtected($name)) {
            $this->error("Module [{$name}] is protected and cannot be removed.");

            return self::FAILURE;
        }

        if (! in_array($name, ModuleServiceProvider::getAllModules(), true)) {
            $this->error("Module [{$name}] not found in modules/ directory.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("This will run modules/{$name}/.init/_remove.php (drops its tables and cleans up) and delete the directory. Continue?", false)) {
            $this->warn('Removal cancelled.');

            return self::SUCCESS;
        }

        if (! ModuleLifecycle::runHook($name, 'remove', $this)) {
            $this->error('Removal aborted: cleanup hook failed.');

            return self::FAILURE;
        }

        ModuleLifecycle::writeDisabledConfig(
            array_values(array_diff(config('modules.disabled', []), [$name]))
        );

        $files->deleteDirectory(base_path("modules/{$name}"));

        ModuleServiceProvider::clearCache();

        $this->info("Module [{$name}] removed.");

        return self::SUCCESS;
    }
}
