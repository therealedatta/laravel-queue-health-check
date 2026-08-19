<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Illuminate\Support\Facades\File;

abstract class StateFile
{
    abstract protected function filename(): string;

    /**
     * The name this file carried before 1.4, when both files also lived in
     * storage/logs by default. It is adopted once so upgrading keeps the state
     * instead of reading as a queue that never started. Can go away in 2.0.
     */
    abstract protected function legacyFilename(): string;

    public function path(): string
    {
        return rtrim(Settings::storagePath(), '/').'/'.$this->filename();
    }

    public function exists(): bool
    {
        return file_exists($this->path());
    }

    public function delete(): void
    {
        if ($this->exists()) {
            unlink($this->path());
        }
    }

    protected function contents(): string
    {
        $this->adoptLegacyFile();

        if (! $this->exists()) {
            return '';
        }

        return trim((string) file_get_contents($this->path()));
    }

    protected function put(string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->path()));

        file_put_contents($this->path(), $contents, LOCK_EX);
    }

    private function adoptLegacyFile(): void
    {
        if ($this->exists()) {
            return;
        }

        // a pre-1.4 file can sit under its old name in the configured directory,
        // or in storage/logs, which is where the default used to point.
        foreach ([dirname($this->path()), storage_path('logs')] as $directory) {
            $legacy = rtrim($directory, '/').'/'.$this->legacyFilename();

            if (file_exists($legacy)) {
                File::ensureDirectoryExists(dirname($this->path()));
                @rename($legacy, $this->path());

                return;
            }
        }
    }
}
