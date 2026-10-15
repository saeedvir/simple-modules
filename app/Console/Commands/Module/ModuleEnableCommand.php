<?php

namespace App\Console\Commands\Module;

use App\Providers\ModuleServiceProvider;
use App\Support\ModuleLifecycle;
use Illuminate\Console\Command;

class ModuleEnableCommand extends Command
{
    protected $signature = 'module:enable {name : The module name (PascalCase)}';

    protected $description = 'Enable a previously disabled module';

    public function handle(): int
    {
        $name = $this->argument('name');

        $allModules = ModuleServiceProvider::getAllModules();

        if (! in_array($name, $allModules)) {
            $this->error("Module [{$name}] not found in modules/ directory.");

            return self::FAILURE;
        }

        $disabled = config('modules.disabled', []);

        if (! in_array($name, $disabled)) {
            $this->warn("Module [{$name}] is already enabled.");

            return self::SUCCESS;
        }

        ModuleLifecycle::writeDisabledConfig(array_values(array_diff($disabled, [$name])));

        $this->call('migrate', ['--force' => true]);

        $migrationPath = "modules/{$name}/database/migrations";

        if (is_dir(base_path($migrationPath))) {
            $this->call('migrate', ['--path' => $migrationPath, '--force' => true]);
        }

        if (! ModuleLifecycle::runHook($name, 'enable', $this)) {
            return self::FAILURE;
        }

        $this->info("Module [{$name}] enabled.");

        return self::SUCCESS;
    }
}
