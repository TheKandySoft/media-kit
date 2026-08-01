<?php

namespace KandySoft\MediaKit\Contracts;

use KandySoft\MediaKit\Storage\MediaDisk;

/**
 * Resolves Laravel disks into the wrapper this package works with.
 */
interface MediaStorage
{
    /**
     * @param  string|null  $disk  a disk from config/filesystems.php; null means the configured default
     */
    public function disk(?string $disk = null): MediaDisk;

    public function defaultDiskName(): string;
}
