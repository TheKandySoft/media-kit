<?php

namespace KandySoft\MediaKit\Exceptions;

use RuntimeException;

/**
 * The disk refused a write.
 *
 * A disk configured with `'throw' => false` reports a failed write by returning
 * false instead of throwing — an object store rejecting an ACL, a full volume, a
 * missing bucket. Left unchecked, that turns into a media record pointing at a
 * file that was never stored, which is only discovered when somebody opens it.
 */
final class MediaNotStored extends RuntimeException
{
    public static function path(string $disk, string $path): self
    {
        return new self("Disk [{$disk}] did not store [{$path}].");
    }
}
