<?php

use KandySoft\MediaKit\Data\MediaCrop;

it('keeps an area inside its picture', function () {
    $crop = MediaCrop::of(0.8, -0.2, 0.5, 1.4);

    expect($crop->toArray())->toBe(['x' => 0.5, 'y' => 0.0, 'width' => 0.5, 'height' => 1.0]);
});

it('reads only the shape it writes', function () {
    expect(MediaCrop::fromArray(['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4])?->toArray())
        ->toBe(['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4])
        ->and(MediaCrop::fromArray(['x' => 0.1]))->toBeNull()
        ->and(MediaCrop::fromArray('0.1,0.2'))->toBeNull()
        ->and(MediaCrop::fromArray(null))->toBeNull();
});

it('knows the whole picture', function () {
    expect(MediaCrop::whole()->isWhole())->toBeTrue()
        ->and(MediaCrop::of(0, 0, 1, 1)->isWhole())->toBeTrue()
        ->and(MediaCrop::of(0, 0, 0.5, 1)->isWhole())->toBeFalse();
});

it('cuts exactly the area at its own proportions', function () {
    // A 7:10 area on the right of a 3000×2000 landscape photo.
    $crop = MediaCrop::of(0.6, 0.0, 1400 / 3000, 1.0);

    expect($crop->frame(3000, 2000, 0.7))->toBe(['x' => 1600, 'y' => 0, 'width' => 1400, 'height' => 2000]);
});

it('widens the frame around the area for wider proportions', function () {
    $crop = MediaCrop::of(0.4, 0.25, 0.2, 0.5);

    // The area is 200×500 of 1000×1000; a square frame holding it is 500×500.
    expect($crop->frame(1000, 1000, 1.0))->toBe(['x' => 250, 'y' => 250, 'width' => 500, 'height' => 500]);
});

it('keeps the frame inside the picture and moves the area off centre in it', function () {
    $crop = MediaCrop::of(0.0, 0.0, 0.1, 0.5);

    expect($crop->frame(1000, 1000, 1.0))->toBe(['x' => 0, 'y' => 0, 'width' => 500, 'height' => 500])
        ->and($crop->focus(1000, 1000, 1.0))->toBe(['x' => 0.1, 'y' => 0.5]);
});

it('takes the largest frame the picture has when the area needs more', function () {
    // A whole-height area asked for wider than the picture allows.
    $crop = MediaCrop::of(0.7, 0.0, 0.3, 1.0);

    expect($crop->frame(1000, 500, 4.0))->toBe(['x' => 0, 'y' => 125, 'width' => 1000, 'height' => 250]);
});

it('centres the focus on an area cut at its own proportions', function () {
    $crop = MediaCrop::of(0.6, 0.0, 1400 / 3000, 1.0);

    expect($crop->focus(3000, 2000, 0.7))->toBe(['x' => 0.5, 'y' => 0.5]);
});

it('spells the same area the same way', function () {
    expect(MediaCrop::of(0.1, 0.2, 0.3, 0.4)->equals(MediaCrop::of(0.10001, 0.2, 0.3, 0.4)))->toBeTrue()
        ->and(MediaCrop::of(0.1, 0.2, 0.3, 0.4)->equals(null))->toBeFalse();
});
