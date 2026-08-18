<?php

namespace KandySoft\MediaKit\Data;

/**
 * One named rendition, resolved to concrete numbers.
 *
 * The configured variants are multipliers of whatever size the caller asked
 * for, so "medium" means nothing until both are known. Resolving it in one
 * place keeps the description of a variant and the generation of it from
 * drifting apart — they used to compute the same numbers twice, and a rendition
 * described at one size and written at another is a file nobody ever reads.
 */
final readonly class VariantSize
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public string $format,
    ) {}
}
