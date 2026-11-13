<?php

namespace pixelwerft\datakeeper\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;

/**
 * Keeps the measurements, the cleanup log and the notification state in one small JSON file in `storage/`. A file
 * instead of a table on purpose: the tool that watches the database must not grow it, and uninstalling leaves nothing
 * behind in the database.
 */
class Store extends Component
{
    public const FILE = '@storage/data-keeper/data.json';

    private ?array $data = null;

    public function path(): string
    {
        return Craft::getAlias(self::FILE);
    }

    /** The whole content (empty array if nothing is stored yet). */
    public function read(): array
    {
        if ($this->data === null) {
            $file = $this->path();
            $json = is_file($file) ? (string)file_get_contents($file) : '';
            $this->data = ($json !== '' ? json_decode($json, true) : null) ?: [];
        }
        return $this->data;
    }

    /** Forgets what was read, so the next read comes from the file again. */
    public function forget(): void
    {
        $this->data = null;
    }

    /**
     * Changes the content under an exclusive lock, so two processes (the page and the cron job) do not overwrite each
     * other. The new content is written to a file of its own and put in place in one step: a crash in between leaves
     * the old file whole, and a reader never sees half a file.
     *
     * @param callable(array): array $change receives the current content, returns the new one
     * @return bool whether the new content was stored
     */
    public function update(callable $change): bool
    {
        $file = $this->path();
        try {
            FileHelper::createDirectory(dirname($file));
        } catch (\Throwable) {
            Craft::warning('Could not create the folder for ' . $file . '.', __METHOD__);
            return false;
        }
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            Craft::warning("Could not open $file for writing.", __METHOD__);
            return false;
        }
        try {
            flock($lock, LOCK_EX);
            $json = is_file($file) ? file_get_contents($file) : '';
            $current = ($json !== '' && $json !== false ? json_decode($json, true) : null) ?: [];
            $new = $change($current);
            // encode first: if that fails, the file stays as it was
            $encoded = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                Craft::warning('Could not encode the data for ' . $file . ': ' . json_last_error_msg(), __METHOD__);
                return false;
            }
            $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($temp, $encoded) === false) {
                @unlink($temp);
                Craft::warning("Could not write $file.", __METHOD__);
                return false;
            }
            if (is_file($file)) {
                // the new file takes the place of the old one: keep who may read and write it
                @chmod($temp, fileperms($file) & 0777);
            }
            if (!@rename($temp, $file)) {
                @unlink($temp);
                Craft::warning("Could not write $file.", __METHOD__);
                return false;
            }
            $this->data = $new;
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->read()[$key] ?? $default;
    }

    /** @return bool whether the value was stored */
    public function set(string $key, mixed $value): bool
    {
        return $this->update(function(array $data) use ($key, $value) {
            $data[$key] = $value;
            return $data;
        });
    }
}
