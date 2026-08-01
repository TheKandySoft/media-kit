<?php

use KandySoft\MediaKit\Data\ImageSize;

it('treats zero and negative dimensions as unset', function () {
    $size = ImageSize::of(0, -5);

    expect($size->width)->toBeNull()
        ->and($size->height)->toBeNull()
        ->and($size->isEmpty())->toBeTrue();
});

it('normalises the format and drops an empty one', function () {
    expect(ImageSize::of(100, 100, 'WEBP')->format)->toBe('webp')
        ->and(ImageSize::of(100, 100, '')->format)->toBeNull();
});

it('uses both dimensions when both were asked for', function () {
    expect(ImageSize::of(800, 600)->resolve(1600, 1200, 300, 300))->toBe([800, 600]);
});

it('keeps the aspect ratio when only the width is known', function () {
    expect(ImageSize::of(800, null)->resolve(1600, 800, 300, 300))->toBe([800, 400]);
});

it('keeps the aspect ratio when only the height is known', function () {
    expect(ImageSize::of(null, 400)->resolve(1600, 800, 300, 300))->toBe([800, 400]);
});

it('falls back to the configured default when nothing was asked for', function () {
    expect((new ImageSize())->resolve(1600, 1200, 320, 240))->toBe([320, 240]);
});

it('squares the requested side when the original has no dimensions', function () {
    expect(ImageSize::of(500, null)->resolve(null, null, 300, 300))->toBe([500, 500]);
});

it('builds a square', function () {
    $size = ImageSize::square(256, 'png');

    expect($size->width)->toBe(256)
        ->and($size->height)->toBe(256)
        ->and($size->format)->toBe('png');
});
