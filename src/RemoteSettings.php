<?php

namespace Watchtower\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Throwable;

use function is_array;
use function is_bool;
use function is_file;
use function is_float;
use function is_int;

/**
 * Sampling and filtering chosen in the Watchtower panel. The agent writes them to a small PHP
 * file (see the agent's SettingsFile) and they are merged over the app's own configuration at
 * boot. The file is a plain `return [...]`, so with opcache reading it costs next to nothing.
 *
 * @internal
 */
final class RemoteSettings
{
    public static function path(Application $app): string
    {
        return $app->bootstrapPath('cache/watchtower-settings.php');
    }

    /**
     * @param  array<string, mixed>  $config  the app's `watchtower` configuration
     * @return array<string, mixed>
     */
    public static function merge(array $config, string $path): array
    {
        if (! ($config['remote_settings'] ?? true) || ! is_file($path)) {
            return $config;
        }

        try {
            $settings = include $path;
        } catch (Throwable) {
            return $config;
        }

        if (! is_array($settings)) {
            return $config;
        }

        foreach (['sampling', 'filtering'] as $group) {
            if (! is_array($settings[$group] ?? null)) {
                continue;
            }

            foreach ($settings[$group] as $key => $value) {
                if (is_int($value) || is_float($value) || is_bool($value)) {
                    $config[$group][$key] = $value; // @phpstan-ignore offsetAccess.nonOffsetAccessible
                }
            }
        }

        return $config;
    }
}
