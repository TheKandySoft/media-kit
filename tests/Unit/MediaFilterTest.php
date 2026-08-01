<?php

use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Enums\MediaType;

it('wants images when no type was named at all', function () {
    expect((new MediaFilter())->wantsImages())->toBeTrue();
});

it('does not want images when only other types were named', function () {
    expect((new MediaFilter(types: [MediaType::Video]))->wantsImages())->toBeFalse();
});

it('maps types down to their stored values', function () {
    $filter = new MediaFilter(types: [MediaType::Image, MediaType::Video]);

    expect($filter->typeValues())->toBe(['image', 'video']);
});

it('builds an image filter with captions and a fallback', function () {
    $filter = MediaFilter::images(captionLocale: 'uk', withFallback: true);

    expect($filter->types)->toBe([MediaType::Image])
        ->and($filter->captionLocale)->toBe('uk')
        ->and($filter->withFallback)->toBeTrue();
});

it('keeps every clause when narrowed step by step', function () {
    $filter = MediaFilter::tagged(['logo'])
        ->withTypes(MediaType::Image)
        ->captionedIn('ru')
        ->fallingBack();

    expect($filter->tags)->toBe(['logo'])
        ->and($filter->types)->toBe([MediaType::Image])
        ->and($filter->captionLocale)->toBe('ru')
        ->and($filter->withFallback)->toBeTrue();
});

it('tells "any locale" apart from "no locale of its own"', function () {
    $any = new MediaFilter();
    $none = new MediaFilter(withoutLocale: true);

    expect($any->locale)->toBeNull()
        ->and($any->withoutLocale)->toBeFalse()
        ->and($none->withoutLocale)->toBeTrue();
});

it('clears the without-locale clause when a locale is named', function () {
    $filter = (new MediaFilter(withoutLocale: true))->inLocale('uk');

    expect($filter->locale)->toBe('uk')
        ->and($filter->withoutLocale)->toBeFalse();
});
