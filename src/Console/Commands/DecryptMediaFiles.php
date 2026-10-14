<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Models\MediaFile;
use Throwable;

/**
 * The way back: store encrypted files as they came.
 *
 * The same care as `media:encrypt` in reverse — written under a new name,
 * checked against the hash taken at encryption, and only then swapped in.
 * Switch encryption off for the disk first, or new uploads keep arriving
 * encrypted.
 */
class DecryptMediaFiles extends Command
{
    protected $signature = 'media:decrypt
                            {--disk=* : Only files on these disks; default: every disk}
                            {--dry-run : Count what would be decrypted without touching it}';

    protected $description = 'Store encrypted media files as plain files again';

    public function handle(MediaStorage $storage, MediaWriter $writer): int
    {
        $disks = (array) $this->option('disk');
        $dryRun = (bool) $this->option('dry-run');
        $done = 0;
        $failed = 0;

        $query = MediaFile::query()->whereNotNull('encryption')->where('is_variant', false);

        if ($disks !== []) {
            $query->whereIn('disk', $disks);
        }

        foreach ($query->lazyById(100, 'uuid') as $media) {
            if ($dryRun) {
                $done++;

                continue;
            }

            try {
                $this->convert($media, $storage, $writer);
                $done++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn(sprintf('%s: %s', $media->uuid, $exception->getMessage()));
            }
        }

        $this->info($dryRun ? "{$done} files would be decrypted." : "Decrypted {$done} files.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function convert(MediaFile $media, MediaStorage $storage, MediaWriter $writer): void
    {
        $disk = $storage->disk($media->disk)->withEncryption(false);
        $envelope = Envelope::of($media) ?? throw new \LogicException('The file is not encrypted.');
        $oldPath = (string) $media->path;
        $directory = dirname($oldPath);
        $newPath = ($directory === '.' ? '' : $directory . '/') . Str::random(40) . '.' . pathinfo($oldPath, PATHINFO_EXTENSION);

        $plain = $disk->openStream($oldPath, $envelope);

        try {
            $disk->write($newPath, $plain);
        } finally {
            fclose($plain);
        }

        if (! hash_equals($envelope->checksum, hash('sha256', $disk->read($newPath, null)))) {
            $disk->delete($newPath);

            throw new \RuntimeException('The decrypted copy does not match the original; the encrypted file is kept.');
        }

        DB::transaction(function () use ($media, $newPath): void {
            $media->forceFill(['path' => $newPath, ...Envelope::columns(null)])->save();
        });

        $disk->delete($oldPath);

        foreach ($media->variants()->get() as $variant) {
            $writer->delete((string) $variant->uuid);
        }
    }
}
