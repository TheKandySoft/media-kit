<?php

use Illuminate\Support\Facades\Route;
use KandySoft\MediaKit\Http\Controllers\MediaController;

/**
 * Prefix and middleware come from config('media-kit.routes'); set
 * media-kit.routes.enabled to false to register nothing at all.
 */
Route::get('{uuid}', [MediaController::class, 'show'])
    ->whereUuid('uuid')
    ->name('media-kit.file');

Route::get('{uuid}/download', [MediaController::class, 'download'])
    ->whereUuid('uuid')
    ->name('media-kit.download');

Route::get('{uuid}/{width}x{height}.{format}', [MediaController::class, 'variant'])
    ->whereUuid('uuid')
    ->whereNumber('width')
    ->whereNumber('height')
    ->where('format', '[a-z0-9]+')
    ->name('media-kit.variant');
