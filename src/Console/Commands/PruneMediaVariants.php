<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\VariantSize;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Services\VariantPruner;

/**
 * Removes renditions nobody will ask for again.
 *
 * Two ways one becomes unreachable. A record can outlive its file — a restore
 * from an older backup, a disk cleaned by hand — and then every request for
 * that size is a miss that regenerates it. And a shop that changes the size it
 * shows pictures at leaves every rendition at the old size behind: the address
 * is derived from the size, so nothing ever asks for them again, and nothing
 * ever deletes them either.
 *
 * The second kind needs to be told what the current size is, because only the
 * application knows: the package resizes to whatever it is handed. Sizes
 * differ by kind of picture, so tell it whose pictures too (`--model`).
 *
 * A third kind needs no telling: renditions made with other settings —
 * quality, crop, metadata — before those changed. Their path is not the one
 * the current settings produce, so nothing asks for them either.
 *
 * Run it after the application has rebuilt whatever still links to the old
 * files (a catalogue index, a page cache): those links would otherwise point
 * at files this command has just removed.
 */
class PruneMediaVariants extends Command
{
    protected $signature = 'media:prune
                            {--width= : Width the application asks pictures at}
                            {--height= : Height the application asks pictures at}
                            {--format= : Format the application asks for, defaults to the configured one}
                            {--model= : Only renditions of files owned by this model class (or morph alias)}
                            {--dry-run : List what would go without deleting anything}';

    protected $description = 'Delete image renditions that can no longer be reached';

    public function handle(
        MediaStorage $storage,
        MediaWriter $writer,
        ImageVariantGenerator $generator,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $requested = $this->requestedSize();
        $removed = 0;

        $model = $this->option('model');

        if ($requested !== null && ! $model) {
            // A size belongs to one kind of picture: product photos, category
            // banners and page images are asked for at different sizes, and
            // judging all of them against one would delete the others.
            $this->warn('No --model given — every owner\'s renditions are judged against this one size.');
        }

        $query = MediaFile::query()->where('is_variant', true)->with('parent');

        if ($model) {
            $query->where('model_type', $model);
        }

        foreach ($query->lazyById(500, 'uuid') as $variant) {
            $reason = $this->unreachable($variant, $storage, $generator, $requested);

            if ($reason === null) {
                continue;
            }

            $this->line(sprintf('%s %s (%s)', $dryRun ? 'would remove' : 'removed', $variant->path, $reason));

            if (! $dryRun) {
                $writer->delete($variant->uuid);
            }

            $removed++;
        }

        $this->info($dryRun
            ? "{$removed} renditions would be removed. Run without --dry-run to do it."
            : "Removed {$removed} renditions.");

        return self::SUCCESS;
    }

    /**
     * Why this rendition can no longer be reached, or null if it still can.
     */
    private function unreachable(
        MediaFile $variant,
        MediaStorage $storage,
        ImageVariantGenerator $generator,
        ?ImageSize $requested,
    ): ?string {
        if (! $storage->disk($variant->disk)->exists($variant->path)) {
            return 'file is gone';
        }

        // A variant without a parent is unreachable by definition, but the
        // schema cascades that away, so it can only appear after surgery.
        $parent = $variant->parent;

        if ($parent === null) {
            return 'original is gone';
        }

        // The path carries the rendition settings. A file whose path is not
        // the one today's settings would give it was made at another quality
        // or crop, and nothing will ask for it again.
        if (app(VariantPruner::class)->isStale($variant)) {
            return 'made with other rendition settings';
        }

        if ($requested === null) {
            return null;
        }

        foreach ($generator->sizesFor($parent->width, $parent->height, $requested, $parent->crop) as $current) {
            if ($this->matches($variant, $current)) {
                return null;
            }
        }

        return 'size is no longer used';
    }

    private function matches(MediaFile $variant, VariantSize $size): bool
    {
        return $variant->width === $size->width
            && $variant->height === $size->height
            && $variant->extension === $size->format;
    }

    /**
     * The size the application currently asks for, if it said.
     *
     * Without it only the broken renditions go — deleting by a guessed size
     * would throw away exactly the files the shop is serving.
     */
    private function requestedSize(): ?ImageSize
    {
        $width = $this->option('width');
        $height = $this->option('height');

        if ($width === null && $height === null) {
            $this->comment('No size given — only broken records will be removed.');

            return null;
        }

        return ImageSize::of(
            $width === null ? null : (int) $width,
            $height === null ? null : (int) $height,
            $this->option('format'),
        );
    }
}
