<?php

namespace KandySoft\MediaKit\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use KandySoft\MediaKit\Console\Commands\AdoptMediaFiles;
use KandySoft\MediaKit\Console\Commands\ShowMediaFile;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Services\MediaReadService;
use KandySoft\MediaKit\Services\MediaWriteService;
use KandySoft\MediaKit\Storage\MediaStorageManager;
use KandySoft\MediaKit\Support\ImageFactory;
use KandySoft\MediaKit\Support\MediaResourceFactory;

class MediaKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->packagePath('config/media-kit.php'), 'media-kit');

        // Every service is a singleton bound to its concrete class, with the
        // contract aliased onto it. That is what makes the facade and an
        // injected contract the same object rather than two lookalikes.
        $this->app->singleton(MediaStorageManager::class);
        $this->app->alias(MediaStorageManager::class, MediaStorage::class);

        $this->app->singleton(MediaFileRepository::class);
        $this->app->singleton(ImageFactory::class);
        $this->app->singleton(ImageVariantGenerator::class);
        $this->app->singleton(MediaResourceFactory::class);

        $this->app->singleton(MediaWriteService::class);
        $this->app->alias(MediaWriteService::class, MediaWriter::class);

        $this->app->singleton(MediaReadService::class);
        $this->app->alias(MediaReadService::class, MediaReader::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->packagePath('database/migrations'));

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                ShowMediaFile::class,
                AdoptMediaFiles::class,
            ]);
        }
    }

    private function registerRoutes(): void
    {
        $routes = $this->app['config']->get('media-kit.routes', []);

        if (! ($routes['enabled'] ?? true)) {
            return;
        }

        Route::prefix($routes['prefix'] ?? 'media')
            ->middleware($routes['middleware'] ?? [])
            ->group($this->packagePath('routes/media.php'));
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            $this->packagePath('config/media-kit.php') => config_path('media-kit.php'),
        ], 'media-kit-config');

        $this->publishes([
            $this->packagePath('database/migrations') => database_path('migrations'),
        ], 'media-kit-migrations');
    }

    private function packagePath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }
}
