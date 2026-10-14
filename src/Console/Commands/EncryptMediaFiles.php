<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Storage\MediaDisk;
use Throwable;

/**
 * Encrypt files stored before encryption was switched on.
 *
 * Each file is written encrypted under a new name, read back and checked
 * against the original's hash, and only then does the record move to it and
 * the original go. A failure at any step leaves the original and its record
 * exactly as they were.
 *
 * Renditions are not converted but deleted: they are copies, and the next
 * request makes them again — encrypted, from the encrypted original.
 */
class EncryptMediaFiles extends Command
{
    protected $signature = 'media:encrypt
                            {--disk=* : Only files on these disks; default: every disk that encrypts}
                            {--dry-run : Count what would be encrypted without touching it}';

    protected $description = 'Encrypt media files stored before encryption was switched on';

    public function handle(MediaStorage $storage, MediaWriter $writer): int
    {
        $disks = (array) $this->option('disk');
        $dryRun = (bool) $this->option('dry-run');
        $done = 0;
        $failed = 0;

        $query = MediaFile::query()->whereNull('encryption')->where('is_variant', false);

        if ($disks !== []) {
            $query->whereIn('disk', $disks);
        }

        foreach ($query->lazyById(100, 'uuid') as $media) {
            $disk = $storage->disk($media->disk);

            // Without --disk only disks that encrypt what they store: a file
            // encrypted on a disk that does not would sit next to new uploads
            // stored as they came, and nobody would have chosen that.
            if ($disks === [] && ! $disk->encrypts()) {
                continue;
            }

            if ($dryRun) {
                $done++;

                continue;
            }

            try {
                $this->convert($media, $disk->withEncryption(true), $writer);
                $done++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn(sprintf('%s: %s', $media->uuid, $exception->getMessage()));
            }
        }

        $this->info($dryRun ? "{$done} files would be encrypted." : "Encrypted {$done} files.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function convert(MediaFile $media, MediaDisk $disk, MediaWriter $writer): void
    {
        $oldPath = (string) $media->path;
        $directory = dirname($oldPath);
        $newPath = ($directory === '.' ? '' : $directory . '/') . Str::random(40) . '.' . pathinfo($oldPath, PATHINFO_EXTENSION);

        $source = $disk->openStream($oldPath, null);

        try {
            $envelope = $disk->write($newPath, $source) ?? throw new \LogicException('The disk did not encrypt.');
        } finally {
            fclose($source);
        }

        if (! hash_equals($envelope->checksum, hash('sha256', $disk->read($newPath, $envelope)))) {
            $disk->delete($newPath);

            throw new \RuntimeException('The encrypted copy does not read back as the original; the original is kept.');
        }

        DB::transaction(function () use ($media, $newPath, $envelope): void {
            $media->forceFill([
                'path' => $newPath,
                'placeholder' => null,
                'size' => $envelope->size,
                ...Envelope::columns($envelope),
            ])->save();
        });

        $disk->delete($oldPath);

        foreach ($media->variants()->get() as $variant) {
            $writer->delete((string) $variant->uuid);
        }
    }
}
