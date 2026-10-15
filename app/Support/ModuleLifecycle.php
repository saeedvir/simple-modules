<?php

namespace App\Support;

use App\Providers\ModuleServiceProvider;
use Illuminate\Console\Command;

/**
 * Runs per-module lifecycle hooks from `modules/{Name}/.init/`.
 *
 * Hooks are plain PHP files executed with `$command` in scope:
 * - `_enable.php`  — run by `module:enable` (re-create what disable removed, flush caches)
 * - `_disable.php` — run by `module:disable` (remove generated rows/caches; tables are kept)
 * - `_remove.php`  — run by `module:remove` (drop tables, clean the migration ledger, then the directory is deleted)
 *
 * Hooks must stay idempotent and work through the Schema/DB facades only.
 */
final class ModuleLifecycle
{
    public const HOOKS = ['enable', 'disable', 'remove'];

    /**
     * Modules listed in `config('modules.protected')` can never be
     * disabled or removed.
     */
    public static function isProtected(string $module): bool
    {
        return in_array($module, config('modules.protected', []), true);
    }

    public static function hookPath(string $module, string $hook): string
    {
        return base_path("modules/{$module}/.init/_{$hook}.php");
    }

    public static function hasHook(string $module, string $hook): bool
    {
        return file_exists(self::hookPath($module, $hook));
    }

    /**
     * Execute a lifecycle hook with `$command` available inside the file.
     * Missing hook files are a warning, failing hooks return false.
     */
    public static function runHook(string $module, string $hook, Command $command): bool
    {
        $path = self::hookPath($module, $hook);

        if (! file_exists($path)) {
            $command->warn("No lifecycle hook [{$path}], skipping.");

            return true;
        }

        try {
            $run = function () use ($path, $command): void {
                require $path;
            };

            $run();
        } catch (\Throwable $exception) {
            $command->error("Lifecycle hook [_{$hook}.php] failed: {$exception->getMessage()}");

            return false;
        }

        return true;
    }

    /**
     * Rewrite the `disabled` list in `config/modules.php`, leaving every
     * other key and comment byte-identical.
     *
     * @param  list<string>  $disabled
     */
    public static function writeDisabledConfig(array $disabled): void
    {
        $disabled = array_values(array_unique($disabled));
        sort($disabled);

        $entries = '';

        foreach ($disabled as $name) {
            $entries .= "        '{$name}',\n";
        }

        if ($entries === '') {
            $entries = "        //\n";
        }

        $path = base_path('config/modules.php');
        $contents = file_get_contents($path);
        $block = "'disabled' => [\n{$entries}    ]";

        $updated = preg_replace("/'disabled'\s*=>\s*\[(?:[^\[\]]*)\]/", $block, $contents, 1, $count);

        if (($count ?? 0) === 0) {
            $trimmed = rtrim($contents);

            if (! str_ends_with($trimmed, '];')) {
                throw new \RuntimeException('Could not locate the disabled list in [config/modules.php].');
            }

            $updated = substr($trimmed, 0, -2)."    'disabled' => [\n{$entries}    ],\n];\n";
        }

        file_put_contents($path, $updated);

        config(['modules.disabled' => $disabled]);

        ModuleServiceProvider::clearCache();
    }
}
