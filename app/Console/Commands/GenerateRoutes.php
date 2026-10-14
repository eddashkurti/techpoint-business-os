<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class GenerateRoutes extends Command
{
    protected $signature = 'techpoint:routes';

    protected $description = 'Regenerate typed frontend routes from the registered Laravel routes';

    public function handle(Filesystem $files): int
    {
        $base = realpath(resource_path('js'));
        if (! $base) {
            return self::FAILURE;
        }
        foreach (['actions', 'routes', 'wayfinder'] as $directory) {
            $target = $base.DIRECTORY_SEPARATOR.$directory;
            if (is_link($target)) {
                $this->error('Refusing a linked generated directory.');

                return self::FAILURE;
            }
            if (is_dir($target)) {
                $resolved = realpath($target);
                if (! $resolved || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)) {
                    return self::FAILURE;
                } $files->deleteDirectory($resolved);
            }
        }

        return $this->call('wayfinder:generate', ['--with-form' => true]);
    }
}
