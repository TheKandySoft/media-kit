<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Exceptions\MediaNotFound;

class ShowMediaFile extends Command
{
    protected $signature = 'media:show {uuid : UUID of the file}
                            {--width= : Variant width}
                            {--height= : Variant height}
                            {--format= : Variant format, defaults to the configured one}
                            {--locale= : Caption locale}';

    protected $description = 'Print the resource a client would receive for one media file';

    public function handle(MediaReader $reader): int
    {
        try {
            $resource = $reader->byUuid(
                (string) $this->argument('uuid'),
                new MediaFilter(captionLocale: $this->option('locale')),
                ImageSize::of(
                    $this->option('width') !== null ? (int) $this->option('width') : null,
                    $this->option('height') !== null ? (int) $this->option('height') : null,
                    $this->option('format'),
                ),
            );
        } catch (MediaNotFound $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($resource, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
