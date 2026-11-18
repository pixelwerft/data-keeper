<?php

namespace pixelwerft\datakeeper\maintenance;

use Craft;
use ZipArchive;

/**
 * Compresses the database backups that lie in the backup folder as plain `.sql` files: each becomes a `.sql.zip`
 * next to it – the name Craft itself gives a zipped backup – and the plain file goes once the zip was checked.
 *
 * A dump is text and shrinks to a fraction. Nothing is lost: `craft db/restore` takes the zip as it is.
 */
class CompressBackups extends Action
{
    public function id(): string
    {
        return 'compress-backups';
    }

    public function figure(): string
    {
        return 'backups';
    }

    public function button(): string
    {
        return self::t('Compress…');
    }

    public function label(): string
    {
        return self::t('Compress backups');
    }

    public function description(): string
    {
        return self::t('Packs every backup that lies in the backup folder as a plain .sql file into a zip file and removes the plain file once the zip was checked. A database backup is text and usually shrinks to a tenth or less.');
    }

    public function notes(): array
    {
        return [
            self::t('Nothing is lost: the zip holds the same file, keeps its date, and «craft db/restore» takes it as it is. Backups that are already compressed (.zip, .gz) are left alone. To remove backups, use «Files…» on the card.'),
        ];
    }

    public function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function permanent(): bool
    {
        return false;
    }

    public function amount(int $count): string
    {
        return self::t('{n, plural, one{# backup that is not compressed} other{# backups that are not compressed}}', ['n' => $count]);
    }

    public function outcome(int $count): string
    {
        return self::t('{n, plural, one{# backup was} other{# backups were}} compressed.', ['n' => $count]);
    }

    public function preview(): array
    {
        $files = $this->plainFiles();
        return [
            'count' => count($files),
            'mb' => round(array_sum(array_column($files, 'size')) / 1048576, 1),
            'sample' => array_map(
                fn(array $file) => basename($file['path']) . ' (' . round($file['size'] / 1048576, 1) . ' MB)',
                array_slice($files, 0, 5),
            ),
        ];
    }

    public function run(?callable $progress = null): array
    {
        $files = $this->plainFiles();
        $done = 0;
        $problems = [];
        foreach ($files as $i => $file) {
            $problem = $this->compress($file['path']);
            if ($problem === null) {
                $done++;
            } else {
                $problems[] = basename($file['path']) . ': ' . $problem;
            }
            if ($progress) {
                $progress($i + 1, count($files));
            }
        }
        return ['count' => $done, 'problems' => $problems];
    }

    /**
     * Zips one file and removes it afterwards. The plain file only goes when the zip is there and holds a file of
     * the same size.
     *
     * @return string|null what went wrong (null: nothing)
     */
    private function compress(string $path): ?string
    {
        $target = $path . '.zip';
        $name = basename($path);
        $size = filesize($path);
        $mtime = filemtime($path);
        if (file_exists($target)) {
            return 'a zip with this name exists already';
        }

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            return 'the zip could not be created';
        }
        $added = $zip->addFile($path, $name);
        // the file is read and written when the archive is closed
        if (!$added || !@$zip->close()) {
            @unlink($target);
            return 'the zip could not be written (is there enough free space?)';
        }

        $check = new ZipArchive();
        $entry = $check->open($target) === true ? $check->statName($name) : false;
        if ($entry !== false) {
            $check->close();
        }
        if ($entry === false || $size === false || (int)$entry['size'] !== $size) {
            @unlink($target);
            return 'the zip did not hold the whole file';
        }

        // the date of the backup stays: «keep the newest N backups» goes by it
        if ($mtime !== false) {
            @touch($target, $mtime);
        }
        @chmod($target, fileperms($path) & 0777);
        if (!@unlink($path)) {
            return 'compressed, but the plain file could not be removed';
        }
        Craft::info("Compressed the backup file $name.", __METHOD__);
        return null;
    }

    /**
     * The plain `.sql` files that lie directly in the backup folder, oldest first.
     *
     * @return array<int, array{path: string, size: int}>
     */
    private function plainFiles(): array
    {
        $folder = Craft::$app->getPath()->getDbBackupPath(false);
        if (!$folder || !is_dir($folder)) {
            return [];
        }
        $files = [];
        foreach (scandir($folder) ?: [] as $name) {
            $path = $folder . DIRECTORY_SEPARATOR . $name;
            if ($name[0] === '.' || !str_ends_with(strtolower($name), '.sql') || is_link($path) || !is_file($path)) {
                continue;
            }
            $files[] = ['path' => $path, 'size' => (int)filesize($path), 'mtime' => (int)filemtime($path)];
        }
        usort($files, fn($a, $b) => [$a['mtime'], $a['path']] <=> [$b['mtime'], $b['path']]);
        return array_map(fn(array $file) => ['path' => $file['path'], 'size' => $file['size']], $files);
    }
}
