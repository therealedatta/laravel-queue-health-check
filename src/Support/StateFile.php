<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Illuminate\Support\Facades\File;

abstract class StateFile
{
    abstract protected function filename(): string;

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
}
