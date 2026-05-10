<?php

declare(strict_types=1);

namespace GruffPhp\Review;

use GruffPhp\Diff\DiffException;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class GitArchiveSnapshot
{
    public function create(string $projectRoot, string $ref): string
    {
        $tempRoot = rtrim(sys_get_temp_dir(), '/') . '/gruff-review-' . bin2hex(random_bytes(6));
        if (!mkdir($tempRoot, 0777, true) && !is_dir($tempRoot)) {
            throw new RuntimeException(sprintf('Unable to create review snapshot directory "%s".', $tempRoot));
        }

        $archive = new Process(['git', 'archive', '--format=tar', $ref], $projectRoot);
        $archive->run();

        if (!$archive->isSuccessful()) {
            $this->remove($tempRoot);
            throw new DiffException(trim($archive->getErrorOutput()) !== ''
                ? trim($archive->getErrorOutput())
                : sprintf('Unable to archive base ref "%s".', $ref));
        }

        $extract = new Process(['tar', '-xf', '-', '-C', $tempRoot]);
        $extract->setInput($archive->getOutput());
        $extract->run();

        if (!$extract->isSuccessful()) {
            $this->remove($tempRoot);
            throw new DiffException(trim($extract->getErrorOutput()) !== ''
                ? trim($extract->getErrorOutput())
                : sprintf('Unable to extract base ref "%s".', $ref));
        }

        return $tempRoot;
    }

    public function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $file->getPathname();

            if ($file->isDir()) {
                rmdir($pathname);
                continue;
            }

            unlink($pathname);
        }

        rmdir($path);
    }
}
