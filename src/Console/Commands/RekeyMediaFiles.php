<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Contracts\KeyProvider;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Encryption\FileCipher;
use KandySoft\MediaKit\Models\MediaFile;
use Throwable;

/**
 * Rewrap every file key with the current application key.
 *
 * Rotation without rewriting a single file: only the small wrapped key in
 * each record changes. Once this has run, no record names the old key, and the
 * old key can leave the configuration.
 */
class RekeyMediaFiles extends Command
{
    protected $signature = 'media:rekey {--dry-run : Count what would be rewrapped without touching it}';

    protected $description = 'Rewrap encrypted media files with the current encryption key';

    public function handle(KeyProvider $keys, FileCipher $cipher): int
    {
        $current = $keys->currentId();
        $dryRun = (bool) $this->option('dry-run');
        $done = 0;
        $failed = 0;

        $query = MediaFile::query()->whereNotNull('encryption')->where('key_id', '!=', $current);

        foreach ($query->lazyById(500, 'uuid') as $media) {
            if ($dryRun) {
                $done++;

                continue;
            }

            try {
                $envelope = $cipher->rewrap(Envelope::of($media) ?? throw new \LogicException('Not encrypted.'), $current);
                $media->forceFill(Envelope::columns($envelope))->save();
                $done++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn(sprintf('%s: %s', $media->uuid, $exception->getMessage()));
            }
        }

        $this->info($dryRun ? "{$done} files would be rewrapped with [{$current}]." : "Rewrapped {$done} files with [{$current}].");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
