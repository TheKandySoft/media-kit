<?php

namespace KandySoft\MediaKit\Services;

use Illuminate\Database\Eloquent\Builder;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\VariantSize;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * Deletes renditions nothing will ask for again — for an application that
 * cleans up after itself rather than waiting for `media:prune` to be run.
 *
 * Two kinds, and only the application knows when to look for the second:
 *
 * - **Stale** renditions were made with settings that have since changed —
 *   quality, sharpening, a file's chosen area. Their path is not the one the
 *   current settings give, so the package recognises them by itself.
 * - **Retired** renditions are of a size the application stopped asking for.
 *   The package resizes to whatever it is handed, so it has to be told which
 *   size went — and only that one: the same picture is often asked for at
 *   several sizes, and judging it against the current one alone would delete
 *   the others.
 *
 * Neither checks whether anything still links to a rendition. Call it after
 * whatever holds direct links — a catalogue index, a page cache — was rebuilt.
 */
final class VariantPruner
{
    public function __construct(
        private readonly MediaWriter $writer,
        private readonly ImageVariantGenerator $generator,
    ) {}

    /**
     * Delete renditions made with other settings than today's.
     *
     * @param  string|null  $original  only the renditions of this file (uuid)
     * @return int  how many were deleted
     */
    public function stale(?string $original = null): int
    {
        $query = MediaFile::query()->where('is_variant', true)->with('parent');

        if ($original !== null) {
            $query->where('parent_uuid', $original);
        }

        $removed = 0;

        foreach ($query->lazyById(500, 'uuid') as $variant) {
            if ($this->isStale($variant)) {
                $this->writer->delete((string) $variant->uuid);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Whether a rendition was made with other settings than today's — or has
     * no original left to belong to.
     */
    public function isStale(MediaFile $variant): bool
    {
        $parent = $variant->parent;

        if ($parent === null) {
            return true;
        }

        return $variant->path !== $this->generator->pathFor(
            (string) $parent->path,
            (int) $variant->width,
            (int) $variant->height,
            (string) $variant->extension,
            $parent->crop,
        );
    }

    /**
     * Delete the renditions a size used to produce for a kind of file.
     *
     * Every rendition the retired size gave is deleted, unless the current
     * size gives the same one — two settings may well share a width.
     *
     * @param  string  $modelType  owner class or morph alias
     * @param  string|null  $tag  only files with this tag (a category's logo, not its banner)
     * @return int  how many were deleted
     */
    public function retired(ImageSize $retired, ?ImageSize $current, string $modelType, ?string $tag = null): int
    {
        $originals = MediaFile::query()
            ->where('is_variant', false)
            ->where('model_type', $modelType)
            ->when($tag !== null, fn(Builder $query) => $query->where('tag', $tag))
            ->with('variants');

        $removed = 0;

        foreach ($originals->lazyById(200, 'uuid') as $original) {
            if (! $original->type->isImage() || $original->variants->isEmpty()) {
                continue;
            }

            $gone = $this->sizes($original, $retired);
            $kept = $current === null ? [] : $this->sizes($original, $current);

            foreach ($original->variants as $variant) {
                $key = $this->key((int) $variant->width, (int) $variant->height, (string) $variant->extension);

                if (isset($gone[$key]) && ! isset($kept[$key])) {
                    $this->writer->delete((string) $variant->uuid);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * @return array<string, true>
     */
    private function sizes(MediaFile $original, ImageSize $size): array
    {
        $keys = [];

        foreach ($this->generator->sizesFor($original->width, $original->height, $size, $original->crop) as $variant) {
            /** @var VariantSize $variant */
            $keys[$this->key($variant->width, $variant->height, $variant->format)] = true;
        }

        return $keys;
    }

    private function key(int $width, int $height, string $format): string
    {
        return "{$width}x{$height}." . strtolower($format);
    }
}
