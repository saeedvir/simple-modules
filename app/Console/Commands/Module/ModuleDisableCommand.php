<?php

namespace App\Console\Commands\Module;

use App\Providers\ModuleServiceProvider;
use App\Support\ModuleLifecycle;
use Illuminate\Console\Command;

class ModuleDisableCommand extends Command
{
    protected $signature = 'module:disable {name : The module name (PascalCase)}';

    protected $description = 'Disable a module (prevents it from loading)';

    public function handle(): int
    {
        $name = $this->argument('name');

        $allModules = ModuleServiceProvider::getAllModules();

        if (! in_array($name, $allModules)) {
            $this->error("Module [{$name}] not found in modules/ directory.");

            return self::FAILURE;
        }

        if (ModuleLifecycle::isProtected($name)) {
            $this->error("Module [{$name}] is protected and cannot be disabled.");

            return self::FAILURE;
        }

        $disabled = config('modules.disabled', []);

        if (in_array($name, $disabled)) {
            $this->warn("Module [{$name}] is already disabled.");

            return self::SUCCESS;
        }

        $disabled[] = $name;

        ModuleLifecycle::writeDisabledConfig($disabled);

        if (! ModuleLifecycle::runHook($name, 'disable', $this)) {
            return self::FAILURE;
        }

        $this->info("Module [{$name}] disabled.");

        return self::SUCCESS;
    }
}
