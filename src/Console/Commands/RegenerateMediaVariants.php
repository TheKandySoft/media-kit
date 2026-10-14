<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use Throwable;

/**
 * Make every rendition again under today's settings.
 *
 * A rendition's path carries the settings it was made with, so new quality or
 * crop reaches a picture only when something asks for it at the new path.
 * That happens by itself — on the next request, or when an index warms the
 * renditions again — but the first visitor of every page then waits for the
 * file. This walks the stored renditions and makes each one again at the same
 * size, so the switch is done before anybody looks.
 *
 * The old files stay until `--prune` (or `media:prune`) removes them: anything
 * that still links to them directly — a page cache, a catalogue index — keeps
 * working until it is rebuilt.
 */
class RegenerateMediaVariants extends Command
{
    protected $signature = 'media:regenerate
                            {--disk=* : Only renditions on these disks}
                            {--model= : Only renditions of files owned by this model class (or morph alias)}
                            {--prune : Delete each old rendition once its replacement exists}
                            {--dry-run : Count what would be made without making it}';

    protected $description = 'Make image renditions again under the current rendition settings';

    public function handle(MediaWriter $writer, ImageVariantGenerator $generator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');
        $made = 0;
        $failed = 0;

        $query = MediaFile::query()->where('is_variant', true)->with('parent');

        if ($disks = (array) $this->option('disk')) {
            $query->whereIn('disk', $disks);
        }

        if ($model = $this->option('model')) {
            $query->where('model_type', $model);
        }

        foreach ($query->lazyById(200, 'uuid') as $variant) {
            $parent = $variant->parent;

            if ($parent === null || $variant->width === null || $variant->height === null) {
                continue;
            }

            $current = $generator->pathFor((string) $parent->path, (int) $variant->width, (int) $variant->height, (string) $variant->extension);

            if ($current === $variant->path) {
                continue;
            }

            if ($dryRun) {
                $made++;

                continue;
            }

            try {
                $writer->ensureVariant($parent, (int) $variant->width, (int) $variant->height, (string) $variant->extension);

                if ($prune) {
                    $writer->delete((string) $variant->uuid);
                }

                $made++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn(sprintf('%s: %s', $variant->uuid, $exception->getMessage()));
            }
        }

        $this->info($dryRun ? "{$made} renditions would be made again." : "Made {$made} renditions again.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
