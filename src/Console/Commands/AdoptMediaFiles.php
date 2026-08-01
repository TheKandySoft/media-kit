<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use Throwable;

/**
 * Registers files that are already sitting on a disk but have no record yet —
 * after a manual copy, or a restore from a backup taken outside the library.
 */
class AdoptMediaFiles extends Command
{
    protected $signature = 'media:adopt
                            {--disk= : Disk to scan, defaults to the configured one}
                            {--tag= : Tag to assign to everything adopted}';

    protected $description = 'Create media records for files already present on a disk';

    public function handle(
        MediaStorage $storage,
        MediaWriter $writer,
        MediaFileRepository $media,
    ): int {
        $disk = $storage->disk($this->option('disk'));
        $files = $disk->filesystem()->allFiles(
            (string) config('media-kit.directory', 'media'),
        );

        $this->info(sprintf('Scanning disk [%s] — %d files found.', $disk->name(), count($files)));

        $adopted = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $path) {
            $bar->advance();

            if ($media->findByPath($disk->name(), $path) !== null) {
                $skipped++;

                continue;
            }

            try {
                $writer->adopt($path, $disk->name(), $this->option('tag'));
                $adopted++;
            } catch (Throwable $exception) {
                $failed++;
                $this->newLine();
                $this->warn(sprintf('%s — %s', $path, $exception->getMessage()));
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Adopted', 'Already known', 'Failed'],
            [[$adopted, $skipped, $failed]],
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
