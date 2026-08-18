<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\VariantSize;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\ImageVariantGenerator;

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
 * application knows: the package resizes to whatever it is handed.
 */
class PruneMediaVariants extends Command
{
    protected $signature = 'media:prune
                            {--width= : Width the application asks pictures at}
                            {--height= : Height the application asks pictures at}
                            {--format= : Format the application asks for, defaults to the configured one}
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

        foreach (MediaFile::query()->where('is_variant', true)->with('parent')->lazyById(500, 'uuid') as $variant) {
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

        if ($requested === null) {
            return null;
        }

        foreach ($generator->sizesFor($parent->width, $parent->height, $requested) as $current) {
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
