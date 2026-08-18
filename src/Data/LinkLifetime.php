<?php

namespace KandySoft\MediaKit\Data;

/**
 * How long one particular set of links should live.
 *
 * A per-call override of the disk's own policy, for the cases that do not fit
 * it: an admin previewing a file it is not meant to hand out permanently, an
 * export that ships addresses to somebody outside. It replaces the policy
 * rather than sitting beside it, so the cache header can never outlive a link
 * that was deliberately made short.
 */
final readonly class LinkLifetime
{
    private function __construct(
        /** Minutes, or null for a link that does not expire. */
        public ?int $minutes,
    ) {}

    public static function minutes(int $minutes): self
    {
        // Zero would read as "permanent" further down, which is the opposite of
        // what a caller asking for a lifetime means.
        return new self(max(1, $minutes));
    }

    public static function permanent(): self
    {
        return new self(null);
    }
}
