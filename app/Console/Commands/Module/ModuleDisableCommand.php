<?php

namespace App\Console\Commands\Module;

use App\Providers\ModuleServiceProvider;
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

        $disabled = config('modules.disabled', []);

        if (in_array($name, $disabled)) {
            $this->warn("Module [{$name}] is already disabled.");

            return self::SUCCESS;
        }

        $disabled[] = $name;
        sort($disabled);

        $this->updateConfig($disabled);

        $this->info("Module [{$name}] disabled. Run 'php artisan config:clear' to apply.");

        return self::SUCCESS;
    }

    protected function updateConfig(array $disabled): void
    {
        $configPath = base_path('config/modules.php');

        $content = "<?php\n\nreturn [\n    /*\n    |--------------------------------------------------------------------------\n    | Disabled Modules\n    |--------------------------------------------------------------------------\n    |\n    | List module names (PascalCase) to disable them. Disabled modules are\n    | not loaded, their routes/migrations/config/views are all skipped.\n    |\n    */\n\n    'disabled' => [\n";

        foreach ($disabled as $name) {
            $content .= "        '{$name}',\n";
        }

        $content .= "    ],\n];\n";

        file_put_contents($configPath, $content);
    }
}
