<?php

namespace Venturecraft\Revisionable;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class RevisionableServiceProvider extends ServiceProvider
{
    public function boot(Filesystem $filesystem)
    {
        $this->publishes([
            __DIR__ . '/../database/migrations/create_revisions_table.php.stub'               => $this->getMigrationFileName($filesystem, 'create_revisions_table.php'),
            __DIR__ . '/../database/migrations/add_parent_column_to_revisions_table.php.stub' => $this->getMigrationFileName($filesystem, 'add_parent_column_to_revisions_table.php'),
        ], 'migrations');
    }

    /**
     * Returns existing migration file if found, else uses the current timestamp.
     *
     * @param Filesystem $filesystem
     * @return string
     */
    protected function getMigrationFileName(Filesystem $filesystem, $file): string
    {
        $timestamp = date('Y_m_d_His');

        $migrationsPath = $this->app->databasePath() . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR;

        return Collection::make($migrationsPath)
            ->flatMap(function ($path) use ($filesystem, $file) {
                return $filesystem->glob($path . '*' . $file);
            })
            ->push("{$migrationsPath}{$timestamp}_{$file}")
            ->first();
    }
}
