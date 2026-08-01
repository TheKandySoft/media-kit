<?php

namespace KandySoft\MediaKit\Storage;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use KandySoft\MediaKit\Contracts\MediaStorage;

final class MediaStorageManager implements MediaStorage
{
    /**
     * @var array<string, MediaDisk>
     */
    private array $disks = [];

    public function __construct(
        private readonly Filesystems $filesystems,
        private readonly Config $config,
    ) {}

    public function disk(?string $disk = null): MediaDisk
    {
        $name = $disk ?? $this->defaultDiskName();

        return $this->disks[$name] ??= new MediaDisk(
            name: $name,
            filesystem: $this->filesystems->disk($name),
            public: $this->isPublic($name),
            temporaryUrlTtl: (int) $this->config->get('media-kit.temporary_url_ttl', 60),
        );
    }

    public function defaultDiskName(): string
    {
        return (string) $this->config->get('media-kit.disk', 'public');
    }

    /**
     * A disk is public when it says so itself. Defaulting to private is the
     * safe direction: mislabelling a private disk as public would hand out
     * permanent links to files that were meant to stay behind a signature.
     */
    private function isPublic(string $disk): bool
    {
        return $this->config->get("filesystems.disks.{$disk}.visibility") === 'public';
    }
}
