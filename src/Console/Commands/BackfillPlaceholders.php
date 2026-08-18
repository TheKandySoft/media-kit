<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Support\PlaceholderFactory;
use Throwable;

/**
 * Fills in placeholders for images stored before the library kept them.
 *
 * Without this the column stays empty until every picture happens to be
 * uploaded again, which for a catalogue means never — and a feature that only
 * applies to files added from today on is not much of a feature.
 *
 * Variants are skipped: a placeholder stands in for the picture, not for each
 * of its renditions.
 */
class BackfillPlaceholders extends Command
{
    protected $signature = 'media:placeholders
                            {--force : Rebuild the ones that already exist}
                            {--chunk=100 : How many files to read at a time}';

    protected $description = 'Generate the missing image placeholders';

    public function handle(MediaStorage $storage, PlaceholderFactory $placeholders): int
    {
        $query = MediaFile::query()
            ->where('type', MediaType::Image->value)
            ->where('is_variant', false);

        if (! $this->option('force')) {
            $query->whereNull('placeholder');
        }

        $filled = 0;
        $failed = 0;

        foreach ($query->lazyById((int) $this->option('chunk'), 'uuid') as $media) {
            try {
                $placeholder = $placeholders->forContents($storage->disk($media->disk)->get($media->path));
            } catch (Throwable $exception) {
                // A file that cannot be read is a problem worth naming, but not
                // one that should stop the rest of the catalogue being filled.
                $failed++;
                $this->warn(sprintf('%s: %s', $media->uuid, $exception->getMessage()));

                continue;
            }

            if ($placeholder === null) {
                continue;
            }

            $media->forceFill(['placeholder' => $placeholder])->save();
            $filled++;
        }

        $this->info("Filled {$filled} placeholders." . ($failed > 0 ? " {$failed} files could not be read." : ''));

        return self::SUCCESS;
    }
}
