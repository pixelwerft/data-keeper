<?php

namespace pixelwerft\datakeeper\cleanup;

use craft\helpers\FileHelper;

/**
 * Base for rules that remove files from one folder: finds the candidates, removes only regular files that lie
 * directly in that folder.
 */
abstract class FileRule extends Rule
{
    /** The folder the rule works in (null: not available). */
    abstract protected function folder(): ?string;

    /** @return string[] glob-style file name patterns the rule may touch */
    abstract protected function patterns(): array;

    /**
     * @param array<int, array{path: string, size: int, mtime: int}> $files all matching files, newest first
     * @return array<int, array{path: string, size: int, mtime: int}> the ones to remove
     */
    abstract protected function select(array $files): array;

    /** @return array<int, array{path: string, size: int, mtime: int}> */
    protected function candidates(): array
    {
        $folder = $this->folder();
        if (!$folder || !is_dir($folder)) {
            return [];
        }
        $paths = FileHelper::findFiles($folder, ['only' => $this->patterns(), 'recursive' => false]);
        $files = [];
        foreach ($paths as $path) {
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $files[] = ['path' => $path, 'size' => (int)filesize($path), 'mtime' => (int)filemtime($path)];
        }
        usort($files, fn($a, $b) => [$b['mtime'], $b['path']] <=> [$a['mtime'], $a['path']]);
        return $this->select($files);
    }

    public function preview(): array
    {
        $files = $this->candidates();
        $bytes = array_sum(array_column($files, 'size'));
        return [
            'count' => count($files),
            'mb' => round($bytes / 1048576, 1),
            'oldest' => $files ? min(array_column($files, 'mtime')) : null,
            'sample' => array_map(
                fn($f) => basename($f['path']) . ' (' . date('Y-m-d', $f['mtime']) . ', ' . round($f['size'] / 1048576, 1) . ' MB)',
                array_slice($files, 0, 5),
            ),
        ];
    }

    public function run(?callable $progress = null): int
    {
        $files = $this->candidates();
        $total = count($files);
        $removed = 0;
        foreach ($files as $i => $file) {
            if (@unlink($file['path'])) {
                $removed++;
            }
            if ($progress) {
                $progress($i + 1, $total);
            }
        }
        return $removed;
    }
}
