<?php

namespace Watchtower\LaravelAgent;

use Throwable;

use function array_key_exists;
use function bin2hex;
use function chmod;
use function dirname;
use function file_put_contents;
use function hash;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function random_bytes;
use function rename;
use function unlink;
use function var_export;

/**
 * Writes the sampling and filtering settings chosen in the Watchtower panel (sent with every
 * successful authentication) to a small PHP file the application reads at boot. Only known keys
 * with valid values are written; the file only changes when the settings do, and is removed when
 * the panel has none, so the application's own configuration applies.
 *
 * @internal
 */
final class SettingsFile
{
    /** @var array<string, list<string>> */
    private const KEYS = [
        'sampling' => ['requests', 'commands', 'exceptions', 'scheduled_tasks'],
        'filtering' => ['ignore_cache_events', 'ignore_mail', 'ignore_notifications', 'ignore_outgoing_requests', 'ignore_queries'],
    ];

    private ?string $writtenHash = null;

    public function __construct(
        private string $path,
    ) {
        //
    }

    /**
     * @param  array<mixed>|null  $settings
     * @return bool whether the file changed
     */
    public function apply(?array $settings): bool
    {
        $clean = self::clean($settings ?? []);
        $hash = hash('xxh128', var_export($clean, true));

        if ($hash === $this->writtenHash) {
            return false;
        }

        try {
            if ($clean === []) {
                if (is_file($this->path)) {
                    unlink($this->path);
                }
            } else {
                if (! is_dir(dirname($this->path))) {
                    return false;
                }

                $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
                file_put_contents($temporary, "<?php\n\n// Written by the Watchtower agent from the panel's settings. Do not edit.\n\nreturn ".var_export($clean, true).";\n");
                chmod($temporary, 0644);
                rename($temporary, $this->path);
            }
        } catch (Throwable) {
            return false;
        }

        $this->writtenHash = $hash;

        return true;
    }

    /**
     * Keep only known settings with valid values: sample rates between 0 and 1, filters as booleans.
     *
     * @param  array<mixed>  $settings
     * @return array<string, array<string, float|bool>>
     */
    public static function clean(array $settings): array
    {
        $clean = [];

        foreach (self::KEYS as $group => $keys) {
            $values = $settings[$group] ?? null;

            if (! is_array($values)) {
                continue;
            }

            foreach ($keys as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $value = $values[$key];

                if ($group === 'sampling' && (is_int($value) || is_float($value)) && $value >= 0 && $value <= 1) {
                    $clean[$group][$key] = (float) $value;
                } elseif ($group === 'filtering' && is_bool($value)) {
                    $clean[$group][$key] = $value;
                }
            }
        }

        return $clean;
    }
}
