<?php

use KandySoft\MediaKit\Data\ImageVariant;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaResource;
use KandySoft\MediaKit\Enums\MediaType;

function mediaResource(array $overrides = []): MediaResource
{
    return new MediaResource(...array_merge([
        'uuid' => '99aa4aa1-066c-46c5-9897-afe566f07981',
        'type' => MediaType::Image,
        'url' => 'https://cdn.example/media/a.webp',
    ], $overrides));
}

it('never exposes the disk, the path or the owning model', function () {
    $payload = mediaResource()->toArray();

    expect($payload)->not->toHaveKey('disk')
        ->and($payload)->not->toHaveKey('path')
        ->and($payload)->not->toHaveKey('model_type')
        ->and($payload)->not->toHaveKey('model_id')
        ->and($payload)->not->toHaveKey('id')
        ->and($payload)->not->toHaveKey('meta');
});

it('computes the aspect ratio only when both sides are known', function () {
    expect(mediaResource(['width' => 1600, 'height' => 800])->aspect())->toBe(2.0)
        ->and(mediaResource(['width' => 1600])->aspect())->toBeNull()
        ->and(mediaResource()->aspect())->toBeNull();
});

it('omits dimensions it does not have instead of reporting nulls', function () {
    $payload = mediaResource()->toArray();

    expect($payload)->not->toHaveKey('width')
        ->and($payload)->not->toHaveKey('height')
        ->and($payload)->not->toHaveKey('aspect')
        ->and($payload)->not->toHaveKey('variants');
});

it('flattens the selected caption to alt and title', function () {
    $payload = mediaResource(['caption' => new MediaCaption('uk', alt: 'Опис', title: 'Назва')])->toArray();

    expect($payload['alt'])->toBe('Опис')
        ->and($payload['title'])->toBe('Назва');
});

it('keeps every caption keyed by locale', function () {
    $payload = mediaResource(['captions' => [
        'uk' => new MediaCaption('uk', alt: 'Опис'),
        'en' => new MediaCaption('en', alt: 'Caption'),
    ]])->toArray();

    expect($payload['captions'])->toBe([
        'uk' => ['alt' => 'Опис', 'title' => null],
        'en' => ['alt' => 'Caption', 'title' => null],
    ]);
});

it('exposes variants by name and carries their aspect', function () {
    $media = mediaResource(['variants' => [
        'large' => new ImageVariant(800, 400, 'https://cdn.example/large.webp'),
    ]]);

    expect($media->variant('large')?->aspect())->toBe(2.0)
        ->and($media->variant('missing'))->toBeNull()
        ->and($media->toArray()['variants']['large']['url'])->toBe('https://cdn.example/large.webp');
});

it('serialises to json through the same shape', function () {
    expect(json_decode(json_encode(mediaResource()), true))->toBe(mediaResource()->toArray());
});
