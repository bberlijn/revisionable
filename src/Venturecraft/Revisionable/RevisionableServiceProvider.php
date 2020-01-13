<?php

namespace Spatie\Permission;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class RevisionableServiceProvider extends ServiceProvider
{
    public function boot(Filesystem $filesystem)
    {
        if (isNotLumen()) {
            $this->publishes([
                __DIR__ . '/../database/migrations/create_revisions_tables.php.stub' => $this->getMigrationFileName($filesystem),
            ], 'migrations');
        }
    }

    /**
     * Returns existing migration file if found, else uses the current timestamp.
     *
     * @param Filesystem $filesystem
     * @return string
     */
    protected function getMigrationFileName(Filesystem $filesystem): string
    {
        $timestamp = date('Y_m_d_His');

        return Collection::make($this->app->databasePath() . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR)
            ->flatMap(function ($path) use ($filesystem) {
                return $filesystem->glob($path . '*_create_revisions_tables.php');
            })->push($this->app->databasePath() . "/migrations/{$timestamp}_create_revisions_tables.php")
            ->first();
    }
}
