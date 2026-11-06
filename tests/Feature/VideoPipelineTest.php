<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaPoster;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Events\VideoProcessed;
use KandySoft\MediaKit\Jobs\ProcessVideo;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\VariantPruner;
use KandySoft\MediaKit\Support\VideoProbe;
use KandySoft\MediaKit\Support\VideoRenditions;
use Symfony\Component\Process\Process;

/**
 * Відео: веб-копія, постер, розміри.
 *
 * Завантажене відео лишається як є, а для сторінки з нього робиться копія,
 * яку грає будь-який браузер (H.264 у MP4, зменшена, без метаданих), і кадр
 * для постера — звичайне зображення з розмірами й заглушкою. Ролики для
 * перевірки малює сам ffmpeg: без нього тести пропускаються.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    if (! videoToolsInstalled()) {
        $this->markTestSkipped('ffmpeg/ffprobe are not installed.');
    }

    Storage::fake('public');
    config([
        'media-kit.disk' => 'public',
        'media-kit.directory' => 'media',
        'media-kit.video.preset' => 'ultrafast',
        'media-kit.video.max_dimension' => 640,
    ]);
});

function videoToolsInstalled(): bool
{
    static $installed;

    return $installed ??= (new Process(['ffmpeg', '-version']))->run() === 0
        && (new Process(['ffprobe', '-version']))->run() === 0;
}

/**
 * A short clip drawn by ffmpeg: a test pattern, a tone when asked, turned or
 * tagged as HDR the way a phone writes it.
 */
function makeClip(int $width = 320, int $height = 180, bool $audio = true, int $rotate = 0, bool $hdr = false, string $extension = 'mov'): string
{
    $directory = sys_get_temp_dir() . '/media-kit-clips';
    @mkdir($directory, 0o777, true);
    $plain = $directory . '/' . uniqid('clip') . '.mp4';

    $command = ['ffmpeg', '-nostdin', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', "testsrc=size={$width}x{$height}:rate=30:duration=2"];

    if ($audio) {
        $command = [...$command, '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2'];
    }

    $command = [...$command, '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-metadata', 'location=+50.4501+030.5234/'];

    if ($hdr) {
        // Tags on the frames, the way a phone's encoder writes them.
        $command = [...$command, '-vf', 'setparams=color_primaries=bt2020:color_trc=arib-std-b67:colorspace=bt2020nc'];
    }

    $command = [...$command, ...($audio ? ['-c:a', 'aac', '-shortest'] : []), $plain];
    (new Process($command))->mustRun();

    $out = $directory . '/' . uniqid('clip') . '.' . $extension;
    $remux = ['ffmpeg', '-nostdin', '-loglevel', 'error', '-y'];

    if ($rotate !== 0) {
        $remux = [...$remux, '-display_rotation', (string) $rotate];
    }

    (new Process([...$remux, '-i', $plain, '-c', 'copy', $out]))->mustRun();
    @unlink($plain);

    return $out;
}

function storeClip(string $path, ?string $name = null): MediaFile
{
    return app(MediaWriter::class)->store(
        MediaUpload::file(new UploadedFile($path, $name ?? basename($path), null, null, true), tag: 'gallery'),
        videoOwnerModel(),
    );
}

function videoOwnerModel(): MediaFile
{
    return MediaFile::query()->create([
        'disk' => 'public',
        'path' => 'media/owners/' . uniqid() . '.png',
        'type' => MediaType::Image,
        'is_public' => true,
    ]);
}

function copyOf(MediaFile $video): ?MediaFile
{
    return app(VideoRenditions::class)->copyOf($video->refresh()->load('variants'));
}

function posterOf(MediaFile $video): ?MediaFile
{
    return app(VideoRenditions::class)->posterOf($video->refresh()->load('variants'));
}

function probeStored(MediaFile $media): array
{
    $local = app(MediaStorage::class)->disk($media->disk)->pull($media->path, Envelope::of($media));

    try {
        $process = new Process(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $local->path()]);
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    } finally {
        $local->delete();
    }
}

it('reads the size and length of a video while storing it', function () {
    Queue::fake();

    $video = storeClip(makeClip(320, 180));

    expect($video->type)->toBe(MediaType::Video)
        ->and($video->width)->toBe(320)
        ->and($video->height)->toBe(180)
        ->and($video->duration)->toBeGreaterThan(1.5)->toBeLessThan(2.5);

    Queue::assertPushed(ProcessVideo::class, fn(ProcessVideo $job): bool => $job->uuid === $video->uuid);
});

it('makes an MP4 copy and a poster, and keeps the original', function () {
    $source = makeClip(320, 180);
    $video = storeClip($source);

    $copy = copyOf($video);
    $poster = posterOf($video);

    expect($copy)->not->toBeNull()
        ->and($copy->type)->toBe(MediaType::Video)
        ->and($copy->mime_type)->toBe('video/mp4')
        ->and($copy->extension)->toBe('mp4')
        ->and($copy->is_variant)->toBeTrue()
        ->and([$copy->width, $copy->height])->toBe([320, 180])
        ->and($poster)->not->toBeNull()
        ->and($poster->type)->toBe(MediaType::Image)
        ->and([$poster->width, $poster->height])->toBe([320, 180])
        ->and($poster->placeholder)->toStartWith('data:image/webp;base64,')
        ->and($video->refresh()->placeholder)->toBe($poster->placeholder);

    Storage::disk('public')->assertExists([$video->path, $copy->path, $poster->path]);

    $probe = probeStored($copy);
    $stream = collect($probe['streams'])->firstWhere('codec_type', 'video');

    expect($stream['codec_name'])->toBe('h264')
        ->and($stream['pix_fmt'])->toBe('yuv420p')
        // Nothing of the camera's metadata survives — GPS least of all.
        ->and($probe['format']['tags'] ?? [])->not->toHaveKey('location');
});

it('puts the index at the front, so playback starts before the file is in', function () {
    $copy = copyOf(storeClip(makeClip()));
    $bytes = (string) Storage::disk('public')->get($copy->path);

    expect(strpos($bytes, 'moov'))->toBeLessThan(strpos($bytes, 'mdat'));
});

it('scales a large video down to the longest side allowed, keeping it even', function () {
    $copy = copyOf(storeClip(makeClip(1280, 534)));

    expect([$copy->width, $copy->height])->toBe([640, 268]);
});

it('never makes a copy larger than the original', function () {
    $copy = copyOf(storeClip(makeClip(320, 180)));

    expect([$copy->width, $copy->height])->toBe([320, 180]);
});

it('describes a phone video recorded on its side by the way it is shown', function () {
    $video = storeClip(makeClip(320, 180, rotate: 90));
    $copy = copyOf($video);

    expect([$video->refresh()->width, $video->height])->toBe([180, 320])
        ->and([$copy->width, $copy->height])->toBe([180, 320])
        ->and([posterOf($video)->width, posterOf($video)->height])->toBe([180, 320]);

    $stream = collect(probeStored($copy)['streams'])->firstWhere('codec_type', 'video');

    expect([$stream['width'], $stream['height']])->toBe([180, 320]);
});

it('drops the sound when the host asks for silent copies', function () {
    config(['media-kit.video.audio' => false]);

    $copy = copyOf(storeClip(makeClip(audio: true)));

    expect(collect(probeStored($copy)['streams'])->pluck('codec_type')->all())->toBe(['video']);
});

it('keeps the sound by default', function () {
    $copy = copyOf(storeClip(makeClip(audio: true)));

    expect(collect(probeStored($copy)['streams'])->pluck('codec_type')->sort()->values()->all())->toBe(['audio', 'video']);
});

it('makes an SDR copy of an HDR video', function () {
    $copy = copyOf(storeClip(makeClip(hdr: true)));
    $stream = collect(probeStored($copy)['streams'])->firstWhere('codec_type', 'video');

    expect($stream['color_transfer'] ?? null)->toBe('bt709')
        ->and($stream['color_primaries'] ?? null)->toBe('bt709');
});

it('tags an SDR copy as BT.709, so every browser shows the same colours', function () {
    $stream = collect(probeStored(copyOf(storeClip(makeClip())))['streams'])->firstWhere('codec_type', 'video');

    expect($stream['color_space'] ?? null)->toBe('bt709')
        ->and($stream['color_transfer'] ?? null)->toBe('bt709');
});

it('describes a processed video by its copy and poster', function () {
    $video = storeClip(makeClip(320, 180));
    $copy = copyOf($video);

    $resource = app(MediaReader::class)->forModel(
        $video->model,
        new MediaFilter(types: [MediaType::Image, MediaType::Video]),
        ImageSize::of(300, 400),
    )[0];
    $payload = $resource->toArray();

    expect($payload['type'])->toBe('video')
        ->and($payload['url'])->toEndWith($copy->path)
        ->and($payload['mime_type'])->toBe('video/mp4')
        ->and($payload['extension'])->toBe('mp4')
        ->and($payload['original_name'])->toEndWith('.mov')
        ->and($payload['width'])->toBe(320)
        ->and($payload['height'])->toBe(180)
        ->and($payload['duration'])->toBeGreaterThan(1.5)
        ->and($payload['placeholder'])->toStartWith('data:image/webp;base64,')
        ->and($payload['poster']['type'])->toBe('image')
        ->and($payload['poster']['url'])->toEndWith(posterOf($video)->path)
        ->and($payload['poster']['variants'])->toHaveKeys(['large', 'medium', 'small'])
        ->and($payload)->not->toHaveKey('variants');
});

it('serves the original until the copy is made', function () {
    Queue::fake();

    $video = storeClip(makeClip());
    $payload = app(MediaReader::class)->byUuid($video->uuid)->toArray();

    expect($payload['url'])->toEndWith($video->path)
        ->and($payload['mime_type'])->toBe($video->mime_type)
        ->and($payload)->not->toHaveKey('poster');
});

it('warms the renditions of a video\'s poster', function () {
    $video = storeClip(makeClip(320, 180));

    $warmed = app(MediaWriter::class)->warmVariants($video->refresh(), ImageSize::of(300, 400));

    expect($warmed)->not->toBeEmpty()
        ->and(collect($warmed)->pluck('parent_uuid')->unique()->all())->toBe([posterOf($video)->uuid]);
});

it('tells the application when a video is ready', function () {
    Event::fake([VideoProcessed::class]);

    $video = storeClip(makeClip());

    Event::assertDispatched(VideoProcessed::class, fn(VideoProcessed $event): bool => $event->video->uuid === $video->uuid);
});

it('keeps today\'s copy and poster when pruning, and drops those of old settings', function () {
    $video = storeClip(makeClip());
    $old = [copyOf($video), posterOf($video)];

    expect(app(VariantPruner::class)->stale())->toBe(0);

    config(['media-kit.video.crf' => 30]);

    expect(app(VariantPruner::class)->isStale($old[0]->refresh()))->toBeTrue()
        ->and(app(VariantPruner::class)->isStale($old[1]->refresh()))->toBeFalse();

    $this->artisan('media:videos')->assertSuccessful();

    $copy = copyOf($video);

    expect($copy->uuid)->not->toBe($old[0]->uuid)
        ->and(MediaFile::query()->find($old[0]->uuid))->toBeNull()
        ->and(posterOf($video)->uuid)->toBe($old[1]->uuid);

    Storage::disk('public')->assertMissing($old[0]->path);
    Storage::disk('public')->assertExists($copy->path);
});

it('makes new renditions when a video is replaced', function () {
    $video = storeClip(makeClip(320, 180));
    $old = [copyOf($video)->path, posterOf($video)->path];

    app(MediaWriter::class)->replace($video->uuid, new UploadedFile(makeClip(240, 320), 'new.mov', null, null, true));

    expect([copyOf($video)->width, copyOf($video)->height])->toBe([240, 320]);

    Storage::disk('public')->assertMissing($old);
});

it('deletes the copy, the poster and its renditions with the video', function () {
    $video = storeClip(makeClip());
    $copy = copyOf($video);
    $poster = posterOf($video);
    $renditions = app(MediaWriter::class)->warmVariants($video->refresh(), ImageSize::of(300, 400));

    app(MediaWriter::class)->delete($video->uuid);

    Storage::disk('public')->assertMissing([
        $video->path,
        $copy->path,
        $poster->path,
        ...collect($renditions)->pluck('path')->all(),
    ]);
    expect(MediaFile::query()->count())->toBe(1); // the owner
});

it('stores a video as it came when ffmpeg is not there', function () {
    config(['media-kit.video.ffmpeg' => '/nowhere/ffmpeg']);

    $video = storeClip(makeClip());
    $payload = app(MediaReader::class)->byUuid($video->uuid)->toArray();

    expect($video->refresh()->variants)->toBeEmpty()
        ->and($payload['url'])->toEndWith($video->path);

    $this->artisan('media:videos')->assertFailed();
});

it('does nothing with video when processing is off', function () {
    Queue::fake();
    config(['media-kit.video.enabled' => false]);

    $video = storeClip(makeClip());

    Queue::assertNothingPushed();
    expect($video->width)->toBeNull();
});

it('sends the job where the host says', function () {
    Queue::fake();
    config(['media-kit.video.connection' => 'redis-media', 'media-kit.video.queue' => 'media']);

    storeClip(makeClip());

    Queue::assertPushedOn('media', ProcessVideo::class, fn(ProcessVideo $job): bool => $job->connection === 'redis-media');
});

it('processes the videos stored before the package made copies', function () {
    config(['media-kit.video.enabled' => false]);
    $video = storeClip(makeClip());
    config(['media-kit.video.enabled' => true]);

    expect(copyOf($video))->toBeNull();

    $this->artisan('media:videos')->assertSuccessful();

    expect(copyOf($video))->not->toBeNull()
        ->and(posterOf($video))->not->toBeNull()
        ->and($video->refresh()->width)->toBe(320)
        ->and($video->duration)->toBeGreaterThan(1.5);
});

it('keeps the copy and poster of an encrypted video encrypted', function () {
    config([
        'media-kit.encryption.enabled' => true,
        'media-kit.encryption.keys' => 'k1:' . base64_encode(str_repeat('a', 32)),
        'media-kit.encryption.current' => '',
    ]);

    $video = storeClip(makeClip());
    $copy = copyOf($video);
    $poster = posterOf($video);

    expect($copy->encryption)->toBe(Envelope::SCHEME)
        ->and($poster->encryption)->toBe(Envelope::SCHEME)
        // A readable copy in the database would undo the encryption.
        ->and($poster->placeholder)->toBeNull()
        ->and(collect(probeStored($copy)['streams'])->firstWhere('codec_type', 'video')['codec_name'])->toBe('h264');
});

it('reads a stored side and length with ffprobe', function () {
    $info = app(VideoProbe::class)->probe(makeClip(320, 180, audio: false));

    expect($info->width)->toBe(320)
        ->and($info->hasAudio)->toBeFalse()
        ->and($info->fps)->toBe(30.0);
});

it('encodes on one thread with a short lookahead, for a small worker', function () {
    config(['media-kit.video.threads' => 1, 'media-kit.video.lookahead' => 10]);

    $copy = copyOf(storeClip(makeClip(320, 180)));

    expect($copy)->not->toBeNull()
        ->and(collect(probeStored($copy)['streams'])->firstWhere('codec_type', 'video')['codec_name'])->toBe('h264');
});

it('keeps storing pictures on a schema without the duration column', function () {
    // A host a migration behind the package, or a test that builds the table
    // from a list of the package's migrations.
    \Illuminate\Support\Facades\Schema::table('media_files', fn($table) => $table->dropColumn('duration'));

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('photo.png', 40, 40)));

    expect($media->exists)->toBeTrue();
});

it('cuts the poster from the area chosen for the video, and says where to move the video', function () {
    $video = storeClip(makeClip(320, 180));
    $wholePlaceholder = $video->refresh()->placeholder;

    // The right third of a wide clip, for a tall frame.
    app(MediaWriter::class)->crop($video->uuid, \KandySoft\MediaKit\Data\MediaCrop::of(0.6, 0.0, 0.4, 1.0));

    $poster = posterOf($video);

    expect($poster->crop?->key())->toBe($video->refresh()->crop?->key())
        ->and($video->placeholder)->toBe($poster->placeholder)
        ->and($video->placeholder)->not->toBe($wholePlaceholder);

    $payload = app(MediaReader::class)->byUuid($video->uuid, size: ImageSize::of(70, 100))->toArray();

    // A 7:10 frame of a 16:9 clip around its right third: as far right as it goes.
    expect($payload['focus']['x'])->toBeGreaterThan(0.95)
        ->and($payload['focus']['y'])->toBe(0.5)
        ->and($payload['poster']['url'])->toEndWith(posterOf($video)->path)
        ->and($payload['poster']['variants']['large']['url'])->toContain('/media/');
});

it('gives a poster made later the area the video already has', function () {
    config(['media-kit.video.enabled' => false]);
    $video = storeClip(makeClip(320, 180));
    app(MediaWriter::class)->crop($video->uuid, \KandySoft\MediaKit\Data\MediaCrop::of(0.0, 0.0, 0.5, 1.0));
    config(['media-kit.video.enabled' => true]);

    $this->artisan('media:videos')->assertSuccessful();

    expect(posterOf($video)->crop?->key())->toBe('0.0000,0.0000,0.5000,1.0000');
});

it('keeps a video filling its frame by default, and whole when asked', function () {
    $video = storeClip(makeClip());

    expect(app(MediaReader::class)->byUuid($video->uuid)->toArray())->not->toHaveKey('fit');

    app(MediaWriter::class)->fit($video->uuid, \KandySoft\MediaKit\Enums\MediaFit::Contain);

    expect(app(MediaReader::class)->byUuid($video->uuid)->toArray()['fit'])->toBe('contain');

    app(MediaWriter::class)->fit($video->uuid, \KandySoft\MediaKit\Enums\MediaFit::Cover);

    expect($video->refresh()->fit)->toBeNull();
});

it('takes the fit from a synced form, and leaves it when the form says nothing', function () {
    Queue::fake();
    $owner = videoOwnerModel();
    $video = app(MediaWriter::class)->store(
        MediaUpload::fromArray(['file' => new UploadedFile(makeClip(), 'clip.mov', null, null, true), 'fit' => 'contain']),
        $owner,
    );

    expect($video->refresh()->fit)->toBe(\KandySoft\MediaKit\Enums\MediaFit::Contain);

    app(MediaWriter::class)->sync($owner, [MediaUpload::fromArray(['uuid' => $video->uuid])]);
    expect($video->refresh()->fit)->toBe(\KandySoft\MediaKit\Enums\MediaFit::Contain);

    app(MediaWriter::class)->sync($owner, [MediaUpload::fromArray(['uuid' => $video->uuid, 'fit' => 'cover'])]);
    expect($video->refresh()->fit)->toBeNull();
});

it('takes the poster from the second chosen for the video', function () {
    $video = storeClip(makeClip());
    $before = posterOf($video);

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::frame(1.5));

    $after = posterOf($video);

    expect($video->refresh()->poster_at)->toBe(1.5)
        ->and($after->path)->not->toBe($before->path)
        ->and(MediaFile::query()->find($before->uuid))->toBeNull()
        ->and(app(MediaReader::class)->byUuid($video->uuid)->posterAt)->toBe(1.5);
});

it('makes a chosen frame without encoding the video again', function () {
    $video = storeClip(makeClip());
    $copy = copyOf($video);

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::frame(0.5));

    expect(copyOf($video)->uuid)->toBe($copy->uuid)
        ->and(copyOf($video)->updated_at->equalTo($copy->updated_at))->toBeTrue();
});

it('shows the poster made before while a newly chosen frame is being made', function () {
    $video = storeClip(makeClip());
    $before = posterOf($video);
    Queue::fake();

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::frame(1.2));

    expect(posterOf($video)->uuid)->toBe($before->uuid)
        ->and(app(\KandySoft\MediaKit\Services\VideoProcessor::class)->needsProcessing($video->refresh()))->toBeTrue();
    Queue::assertPushed(ProcessVideo::class);
});

it('takes the last frame there is for a second past the end', function () {
    $video = storeClip(makeClip());

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::frame(30));

    expect(posterOf($video))->not->toBeNull()
        ->and(posterOf($video)->width)->toBe(320);
});

it('shows a picture uploaded as the poster, and the frame again once one is chosen', function () {
    $video = storeClip(makeClip());
    $frame = posterOf($video);

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::image(UploadedFile::fake()->image('cover.jpg', 640, 360)));

    $own = posterOf($video);
    $resource = app(MediaReader::class)->byUuid($video->uuid);

    expect($own->uuid)->toBe($video->refresh()->poster_uuid)
        ->and($own->is_variant)->toBeTrue()
        ->and($own->parent_uuid)->toBe($video->uuid)
        ->and($own->width)->toBe(640)
        ->and($resource->posterOwn)->toBeTrue()
        ->and($resource->poster->url)->toEndWith($own->path)
        ->and($video->placeholder)->toBe($own->placeholder)
        // Kept: choosing a frame again needs nothing made.
        ->and(MediaFile::query()->find($frame->uuid))->not->toBeNull();

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::frame(null));

    expect(posterOf($video)->uuid)->toBe($frame->uuid)
        ->and($video->refresh()->poster_uuid)->toBeNull()
        ->and(MediaFile::query()->find($own->uuid))->toBeNull()
        ->and(app(MediaReader::class)->byUuid($video->uuid)->posterOwn)->toBeFalse();
});

it('cuts an uploaded poster from the area chosen for the video', function () {
    $video = storeClip(makeClip(320, 180));
    $area = \KandySoft\MediaKit\Data\MediaCrop::of(0.6, 0.0, 0.4, 1.0);
    app(MediaWriter::class)->crop($video->uuid, $area);

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::image(UploadedFile::fake()->image('cover.png', 320, 180)));

    expect(posterOf($video)->crop?->key())->toBe($area->key());

    app(MediaWriter::class)->crop($video->uuid, null);

    expect(posterOf($video)->crop)->toBeNull();
});

it('keeps an uploaded poster when pruning and when the video is processed again', function () {
    $video = storeClip(makeClip());
    app(MediaWriter::class)->poster($video->uuid, MediaPoster::image(UploadedFile::fake()->image('cover.jpg', 320, 180)));
    $own = posterOf($video);

    app(VariantPruner::class)->stale();
    $this->artisan('media:videos', ['--uuid' => $video->uuid, '--force' => true])->assertSuccessful();

    expect(posterOf($video)->uuid)->toBe($own->uuid);
});

it('deletes an uploaded poster with its video, and replaces one uploaded before', function () {
    $video = storeClip(makeClip());
    app(MediaWriter::class)->poster($video->uuid, MediaPoster::image(UploadedFile::fake()->image('a.jpg', 320, 180)));
    $first = posterOf($video);

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::image(UploadedFile::fake()->image('b.jpg', 320, 180)));
    $second = posterOf($video);

    expect($second->uuid)->not->toBe($first->uuid)
        ->and(MediaFile::query()->find($first->uuid))->toBeNull()
        ->and(Storage::disk('public')->exists($first->path))->toBeFalse();

    app(MediaWriter::class)->delete($video->uuid);

    expect(MediaFile::query()->find($second->uuid))->toBeNull()
        ->and(Storage::disk('public')->exists($second->path))->toBeFalse();
});

it('refuses a poster that is not a picture', function () {
    $video = storeClip(makeClip());

    app(MediaWriter::class)->poster($video->uuid, MediaPoster::image(new UploadedFile(makeClip(), 'clip.mov', null, null, true)));
})->throws(\KandySoft\MediaKit\Exceptions\UnsupportedFileType::class);

it('takes the poster from a synced form, and leaves it when the form says nothing', function () {
    Queue::fake();
    $owner = videoOwnerModel();
    $video = app(MediaWriter::class)->store(
        MediaUpload::fromArray(['file' => new UploadedFile(makeClip(), 'clip.mov', null, null, true), 'poster' => ['at' => 0.8]]),
        $owner,
    );

    expect($video->refresh()->poster_at)->toBe(0.8);
    Queue::assertPushed(ProcessVideo::class, 1);

    app(MediaWriter::class)->sync($owner, [MediaUpload::fromArray(['uuid' => $video->uuid])]);
    expect($video->refresh()->poster_at)->toBe(0.8);

    app(MediaWriter::class)->sync($owner, [MediaUpload::fromArray(['uuid' => $video->uuid, 'poster' => ['at' => null]])]);
    expect($video->refresh()->poster_at)->toBeNull();
});

it('marks the file a list shows for its owner', function () {
    Queue::fake();
    $owner = videoOwnerModel();
    $first = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.jpg', 40, 40)), $owner);
    $second = app(MediaWriter::class)->store(MediaUpload::file(new UploadedFile(makeClip(), 'clip.mov', null, null, true)), $owner);

    app(MediaWriter::class)->sync($owner, [
        MediaUpload::fromArray(['uuid' => $first->uuid, 'cover' => false]),
        MediaUpload::fromArray(['uuid' => $second->uuid, 'cover' => true]),
    ]);

    $payloads = collect(app(MediaReader::class)->forModel($owner))->map->toArray()->keyBy('uuid');

    expect($payloads[$second->uuid]['cover'])->toBeTrue()
        ->and($payloads[$first->uuid])->not->toHaveKey('cover');

    // Said nothing about it — the mark stays.
    app(MediaWriter::class)->sync($owner, [
        MediaUpload::fromArray(['uuid' => $first->uuid]),
        MediaUpload::fromArray(['uuid' => $second->uuid]),
    ]);

    expect($second->refresh()->is_cover)->toBeTrue();
});

it('copies the poster chosen for a video and the cover mark', function () {
    $owner = videoOwnerModel();
    $video = app(MediaWriter::class)->store(MediaUpload::fromArray([
        'file' => new UploadedFile(makeClip(), 'clip.mov', null, null, true),
        'cover' => true,
    ]), $owner);
    app(MediaWriter::class)->poster($video->uuid, MediaPoster::image(UploadedFile::fake()->image('cover.jpg', 320, 180)));

    [$copy] = app(MediaWriter::class)->copy($owner, $target = videoOwnerModel());

    expect($copy->refresh()->is_cover)->toBeTrue()
        ->and(posterOf($copy)->uuid)->toBe($copy->poster_uuid)
        ->and(posterOf($copy)->width)->toBe(320);
});
