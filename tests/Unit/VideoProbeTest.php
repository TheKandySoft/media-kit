<?php

use KandySoft\MediaKit\Data\VideoInfo;
use KandySoft\MediaKit\Support\VideoProbe;

it('reads size, length, sound and frame rate from ffprobe output', function () {
    $info = VideoProbe::fromProbe([
        'streams' => [
            ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'avg_frame_rate' => '60000/1001'],
            ['codec_type' => 'audio'],
        ],
        'format' => ['duration' => '12.345678'],
    ]);

    expect($info)->toEqual(new VideoInfo(1920, 1080, 12.346, true, false, 59.94, null));
});

it('turns the size of a video recorded on its side', function () {
    $matrix = VideoProbe::fromProbe(['streams' => [
        ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'side_data_list' => [['rotation' => -90]]],
    ]]);
    $tag = VideoProbe::fromProbe(['streams' => [
        ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'tags' => ['rotate' => '270']],
    ]]);
    $upsideDown = VideoProbe::fromProbe(['streams' => [
        ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'side_data_list' => [['rotation' => 180]]],
    ]]);

    expect([$matrix->width, $matrix->height])->toBe([1080, 1920])
        ->and([$tag->width, $tag->height])->toBe([1080, 1920])
        ->and([$upsideDown->width, $upsideDown->height])->toBe([1920, 1080]);
});

it('skips a cover picture stored as a video stream', function () {
    $info = VideoProbe::fromProbe(['streams' => [
        ['codec_type' => 'video', 'width' => 600, 'height' => 600, 'disposition' => ['attached_pic' => 1]],
        ['codec_type' => 'video', 'width' => 1280, 'height' => 720],
    ]]);

    expect($info->width)->toBe(1280);
});

it('recognises HDR by its transfer function', function () {
    $hlg = VideoProbe::fromProbe(['streams' => [['codec_type' => 'video', 'width' => 2, 'height' => 2, 'color_transfer' => 'arib-std-b67']]]);
    $sdr = VideoProbe::fromProbe(['streams' => [['codec_type' => 'video', 'width' => 2, 'height' => 2, 'color_transfer' => 'bt709']]]);

    expect($hlg->hdr)->toBeTrue()->and($sdr->hdr)->toBeFalse();
});

it('has nothing to say about a file without a picture', function () {
    expect(VideoProbe::fromProbe(['streams' => [['codec_type' => 'audio']]]))->toBeNull()
        ->and(VideoProbe::fromProbe([]))->toBeNull();
});

it('fits a copy within the longest side, even on both, never upscaled', function () {
    expect((new VideoInfo(3840, 2160, null, false))->fitWithin(1280))->toBe([1280, 720])
        ->and((new VideoInfo(1080, 1920, null, false))->fitWithin(1280))->toBe([720, 1280])
        ->and((new VideoInfo(641, 361, null, false))->fitWithin(1280))->toBe([642, 362])
        ->and((new VideoInfo(1280, 534, null, false))->fitWithin(640))->toBe([640, 268]);
});
