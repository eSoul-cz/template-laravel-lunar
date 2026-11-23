<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Reads every PHP file below a PSR-4 root, such as `app/` for `App\`. */
final class SourceTree
{
    /**
     * @param  string  $directory  path relative to the project root, e.g. `app`
     * @param  string  $rootNamespace  the namespace the directory maps to, e.g. `App`
     * @return list<SourceFile>
     */
    public static function scan(string $directory, string $rootNamespace): array
    {
        $root = dirname(__DIR__, 3) . '/' . $directory;

        if ( ! is_dir($root)) {
            // A missing directory must fail the rules loudly, never make them pass on nothing.
            throw new InvalidArgumentException(sprintf('%s is not a directory.', $root));
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ( ! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $namespace = trim($rootNamespace . '\\' . str_replace('/', '\\', dirname($relative)), '\\.');

            $files[] = SourceFile::fromFile($file->getPathname(), $directory . '/' . $relative, $namespace);
        }

        usort($files, static fn (SourceFile $left, SourceFile $right): int => strcmp($left->path, $right->path));

        return $files;
    }
}
