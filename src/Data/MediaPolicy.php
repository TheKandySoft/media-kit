<?php

namespace KandySoft\MediaKit\Data;

use DateTimeInterface;

/**
 * How one disk hands out what it stores.
 *
 * Visibility and link lifetimes live together on purpose. They answer the same
 * question — how freely may this file travel — and splitting them is what let
 * a link that expires in five minutes be served with a year-long `immutable`
 * cache header: the header asked the configuration, the link asked the disk.
 *
 * The unit of policy is the disk, not the file. That is the point: a passport
 * scan cannot accidentally acquire a permanent address, because it is not
 * stored where permanent addresses are handed out.
 */
final readonly class MediaPolicy
{
    public function __construct(
        public bool $public,
        /** Minutes a private file's link stays valid. */
        public int $temporaryUrlTtl,
        /** Minutes a variant link stays valid; null means it never expires. */
        public ?int $variantUrlTtl,
        /** Seconds a served answer may be reused. */
        public int $cacheTtl,
        /**
         * Whether files written to this disk are encrypted. Such a disk hands
         * out no direct addresses: the store would serve ciphertext.
         */
        public bool $encrypt = false,
    ) {}

    public function withEncryption(bool $encrypt): self
    {
        return new self($this->public, $this->temporaryUrlTtl, $this->variantUrlTtl, $this->cacheTtl, $encrypt);
    }

    public function temporaryUrlExpiry(): DateTimeInterface
    {
        return now()->addMinutes($this->temporaryUrlTtl);
    }

    /**
     * When a variant link stops working — by default, never.
     *
     * The signature stops anyone requesting arbitrary sizes and filling the
     * disk; it is not there to keep the picture secret. An expiry adds nothing
     * to that and takes a lot away: the address changes on every render, so
     * browsers and CDNs re-download bytes they already hold, and anything that
     * reads an address once and fetches it later — an image crawler, a product
     * feed, a link preview — finds it dead.
     */
    public function variantUrlExpiry(): ?DateTimeInterface
    {
        return $this->variantUrlTtl !== null && $this->variantUrlTtl > 0
            ? now()->addMinutes($this->variantUrlTtl)
            : null;
    }

    /**
     * The same policy with one caller's lifetime in place of the disk's.
     *
     * Both kinds of link move together. Shortening only one of them would
     * leave the caller holding a fifteen-minute preview whose thumbnails
     * outlive it by a year.
     */
    public function with(LinkLifetime $lifetime): self
    {
        if ($lifetime->minutes === null) {
            return new self($this->public, $this->temporaryUrlTtl, null, $this->cacheTtl);
        }

        return new self($this->public, $lifetime->minutes, $lifetime->minutes, $this->cacheTtl);
    }
}
