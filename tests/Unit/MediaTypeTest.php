<?php

use KandySoft\MediaKit\Enums\MediaType;

it('recognises an extension regardless of case or leading dot', function () {
    expect(MediaType::fromExtension('PNG'))->toBe(MediaType::Image)
        ->and(MediaType::fromExtension('.png'))->toBe(MediaType::Image)
        ->and(MediaType::fromExtension('png'))->toBe(MediaType::Image);
});

it('returns null for an extension it cannot store', function () {
    expect(MediaType::fromExtension('exe'))->toBeNull()
        ->and(MediaType::supports('exe'))->toBeFalse();
});

it('resolves pdf as a document rather than a vector', function () {
    expect(MediaType::fromExtension('pdf'))->toBe(MediaType::Document);
});

it('separates images from everything else', function () {
    expect(MediaType::Image->isImage())->toBeTrue()
        ->and(MediaType::Video->isImage())->toBeFalse();
});

it('exposes the extensions behind each type', function () {
    expect(MediaType::Image->extensions())->toContain('webp')
        ->and(MediaType::Archive->extensions())->toContain('zip');
});
