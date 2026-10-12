<?php

namespace KandySoft\MediaKit\Storage;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Data\MediaPolicy;

final class MediaStorageManager implements MediaStorage
{
    public function __construct(
        private readonly Filesystems $filesystems,
        private readonly Config $config,
    ) {}

    public function disk(?string $disk = null): MediaDisk
    {
        $name = $disk ?? $this->defaultDiskName();

        // Built fresh rather than memoised: the wrapper is a few fields around
        // a filesystem the framework already caches, and holding on to it would
        // mean holding on to the policy it was built with — configuration
        // changed afterwards would silently keep the old lifetimes.
        return new MediaDisk(
            name: $name,
            filesystem: $this->filesystems->disk($name),
            policy: $this->policyFor($name),
        );
    }

    public function defaultDiskName(): string
    {
        return (string) $this->config->get('media-kit.disk', 'public');
    }

    /**
     * How this disk hands out what it stores.
     *
     * Top-level configuration holds the defaults; `media-kit.disks.<name>`
     * holds the exceptions. A disk nobody mentioned behaves exactly like every
     * other one, so adding a second disk with its own rules costs one entry
     * and changes nothing elsewhere.
     */
    public function policyFor(string $disk): MediaPolicy
    {
        /** @var array<string, mixed> $overrides */
        $overrides = (array) $this->config->get("media-kit.disks.{$disk}", []);

        $value = fn(string $key, mixed $default): mixed => array_key_exists($key, $overrides)
            ? $overrides[$key]
            : $this->config->get("media-kit.{$key}", $default);

        $variantTtl = $value('variant_url_ttl', null);

        return new MediaPolicy(
            public: $this->isPublic($disk, $overrides),
            temporaryUrlTtl: (int) $value('temporary_url_ttl', 60),
            // Empty means a link with no expiry. Zero means the same: "valid
            // for nought minutes" would not be a setting anybody wants.
            variantUrlTtl: $variantTtl === null || $variantTtl === '' ? null : (int) $variantTtl,
            cacheTtl: (int) $value('cache_ttl', 31536000),
        );
    }

    /**
     * A disk is public when it says so itself. Defaulting to private is the
     * safe direction: mislabelling a private disk as public would hand out
     * permanent links to files that were meant to stay behind a signature.
     *
     * `media-kit.disks.<name>.public` says it without touching the disk. On an
     * object store `'visibility' => 'public'` is more than a label: every write
     * then asks for a public-read ACL, which a bucket with ACLs disabled — the
     * default on AWS — refuses outright. Such a bucket is made readable by its
     * own policy, or not at all when a proxy reads it, and only this package
     * needs to know that its addresses are permanent.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function isPublic(string $disk, array $overrides): bool
    {
        if (array_key_exists('public', $overrides)) {
            return (bool) $overrides['public'];
        }

        return $this->config->get("filesystems.disks.{$disk}.visibility") === 'public';
    }
}
