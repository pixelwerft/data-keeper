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

    /**
     * Changes the content under an exclusive lock, so two processes (the page and the cron job) do not overwrite each
     * other.
     *
     * @param callable(array): array $change receives the current content, returns the new one
     */
    public function update(callable $change): void
    {
        $file = $this->path();
        FileHelper::createDirectory(dirname($file));
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            Craft::warning("Could not open $file for writing.", __METHOD__);
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $json = stream_get_contents($handle);
            $current = ($json !== '' && $json !== false ? json_decode($json, true) : null) ?: [];
            $new = $change($current);
            // encode first: if that fails, the file stays as it was instead of being emptied
            $encoded = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                Craft::warning('Could not encode the data for ' . $file . ': ' . json_last_error_msg(), __METHOD__);
                return;
            }
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $encoded) === false) {
                Craft::warning("Could not write $file.", __METHOD__);
                return;
            }
            fflush($handle);
            $this->data = $new;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->read()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->update(function(array $data) use ($key, $value) {
            $data[$key] = $value;
            return $data;
        });
    }
}
