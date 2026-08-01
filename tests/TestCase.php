<?php

namespace KandySoft\MediaKit\Tests;

use Illuminate\Contracts\Config\Repository;
use KandySoft\MediaKit\Providers\MediaKitServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MediaKitServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);

            // The package treats a disk as public when it says so itself, and
            // the framework's own `public` disk does exactly that.
            $config->set('filesystems.disks.public', [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => 'http://localhost/storage',
                'visibility' => 'public',
            ]);

            $config->set('media-kit.disk', 'public');
            $config->set('media-kit.directory', 'media');
            // GD is what a bare CI container has; Imagick is opt-in.
            $config->set('media-kit.image.driver', 'gd');
        });
    }
}
