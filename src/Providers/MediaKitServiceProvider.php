<?php

namespace KandySoft\MediaKit\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use KandySoft\MediaKit\Console\Commands\AdoptMediaFiles;
use KandySoft\MediaKit\Console\Commands\BackfillPlaceholders;
use KandySoft\MediaKit\Console\Commands\DecryptMediaFiles;
use KandySoft\MediaKit\Console\Commands\EncryptMediaFiles;
use KandySoft\MediaKit\Console\Commands\GenerateMediaKey;
use KandySoft\MediaKit\Console\Commands\PruneMediaVariants;
use KandySoft\MediaKit\Console\Commands\RegenerateMediaVariants;
use KandySoft\MediaKit\Console\Commands\RekeyMediaFiles;
use KandySoft\MediaKit\Console\Commands\ShowMediaFile;
use KandySoft\MediaKit\Contracts\KeyProvider;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Contracts\RenditionQuality;
use KandySoft\MediaKit\Encryption\ConfigKeyring;
use KandySoft\MediaKit\Encryption\FileCipher;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Services\MediaReadService;
use KandySoft\MediaKit\Services\MediaWriteService;
use KandySoft\MediaKit\Services\VariantPruner;
use KandySoft\MediaKit\Storage\MediaStorageManager;
use KandySoft\MediaKit\Support\ConfiguredRenditionQuality;
use KandySoft\MediaKit\Support\ImageFactory;
use KandySoft\MediaKit\Support\MediaResourceFactory;
use KandySoft\MediaKit\Support\MediaTypeResolver;
use KandySoft\MediaKit\Support\SignedLinks;

class MediaKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->packagePath('config/media-kit.php'), 'media-kit');
        $this->mergeImageConfig();

        // Every service is a singleton bound to its concrete class, with the
        // contract aliased onto it. That is what makes the facade and an
        // injected contract the same object rather than two lookalikes.
        // Keys come from configuration unless the host binds its own source —
        // a KMS, a vault. `bindIf`, so a binding made earlier wins.
        $this->app->bindIf(KeyProvider::class, ConfigKeyring::class, shared: true);
        // Rendition quality likewise — a host may let someone choose it.
        $this->app->bindIf(RenditionQuality::class, ConfiguredRenditionQuality::class, shared: true);
        $this->app->singleton(FileCipher::class);

        $this->app->singleton(MediaStorageManager::class);
        $this->app->alias(MediaStorageManager::class, MediaStorage::class);

        $this->app->singleton(MediaFileRepository::class);
        $this->app->singleton(ImageFactory::class);
        $this->app->singleton(MediaTypeResolver::class);
        $this->app->singleton(ImageVariantGenerator::class);
        $this->app->singleton(SignedLinks::class);
        $this->app->singleton(MediaResourceFactory::class);

        $this->app->singleton(MediaWriteService::class);
        $this->app->alias(MediaWriteService::class, MediaWriter::class);

        $this->app->singleton(VariantPruner::class);

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
                BackfillPlaceholders::class,
                PruneMediaVariants::class,
                GenerateMediaKey::class,
                EncryptMediaFiles::class,
                DecryptMediaFiles::class,
                RekeyMediaFiles::class,
                RegenerateMediaVariants::class,
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

    /**
     * Merge the `image` block one level deeper than Laravel does.
     *
     * `mergeConfigFrom()` only merges top-level keys, so a host that set one
     * image option in its own `image` array silently lost every other one —
     * the variants, the placeholder, the quality. Each image key is taken from
     * the host when it has it and from the package otherwise; `quality` is
     * merged per format and `original` per key. Lists such as `variants` are still replaced whole, so
     * a host can drop a variant it does not want.
     */
    private function mergeImageConfig(): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        $defaults = (array) ((require $this->packagePath('config/media-kit.php'))['image'] ?? []);
        $config = $this->app->make('config');
        $host = (array) $config->get('media-kit.image', []);

        $image = array_replace($defaults, $host);
        $image['quality'] = array_replace((array) ($defaults['quality'] ?? []), (array) ($host['quality'] ?? []));
        $image['original'] = array_replace((array) ($defaults['original'] ?? []), (array) ($host['original'] ?? []));

        $config->set('media-kit.image', $image);
    }

    private function packagePath(string $path): string
    {
        return dirname(__DIR__, 2) . '/' . $path;
    }
}
