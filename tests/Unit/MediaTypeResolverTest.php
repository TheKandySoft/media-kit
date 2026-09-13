<?php

use Illuminate\Config\Repository;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Support\MediaTypeResolver;

function resolver(array $types = []): MediaTypeResolver
{
    return new MediaTypeResolver(new Repository(['media-kit' => ['types' => $types]]));
}

it('knows the built-in extensions with nothing configured', function () {
    expect(resolver()->fromExtension('PDF'))->toBe(MediaType::Document)
        ->and(resolver()->supports('xml'))->toBeFalse();
});

it('adds configured extensions to their type, normalised', function () {
    $types = resolver(['document' => ['.XML', 'p7s', 'xml']]);

    expect($types->fromExtension('xml'))->toBe(MediaType::Document)
        ->and($types->fromExtension('.P7S'))->toBe(MediaType::Document)
        ->and($types->extensions(MediaType::Document))->toContain('pdf', 'xml', 'p7s')
        ->and(array_count_values($types->extensions(MediaType::Document))['xml'])->toBe(1);
});

it('never moves a built-in extension to another type', function () {
    // Listing png under documents must not switch a picture's variants off.
    expect(resolver(['document' => ['png']])->fromExtension('png'))->toBe(MediaType::Image);
});

it('refuses a type it does not know rather than ignoring the line', function () {
    resolver(['spreadsheet' => ['ods']])->fromExtension('ods');
})->throws(InvalidArgumentException::class, 'media-kit.types names "spreadsheet"');
