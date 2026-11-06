<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Data\VideoInfo;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Makes the web copy of a video and its poster with `ffmpeg`.
 *
 * The copy is what a browser can be given without asking which one it is:
 * H.264 High in 4:2:0, AAC sound (or none), in MP4 with the index at the
 * front. A phone's `.mov` — HEVC, 10-bit, 60 fps, 4K — plays in Safari and
 * nowhere else, and is ten times the bytes a gallery needs.
 *
 * HDR video (HLG from an iPhone, PQ) is tone-mapped to SDR. Squeezed into
 * 8-bit without it, it comes out grey and washed out; when the ffmpeg build
 * cannot tone-map, the encode is repeated without it rather than not made.
 */
final class VideoEncoder
{
    public function __construct(private readonly Config $config) {}

    /**
     * Whether `ffmpeg` can be run here at all.
     */
    public function available(): bool
    {
        try {
            $process = new Process([$this->ffmpeg(), '-hide_banner', '-version']);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Everything that decides how a copy looks — part of its path, so a
     * change in settings makes a new copy instead of keeping the old one.
     */
    public function profile(): string
    {
        return json_encode([
            'max' => $this->maxDimension(),
            'crf' => $this->crf(),
            'preset' => $this->preset(),
            'fps' => $this->maxFps(),
            'lookahead' => $this->lookahead(),
            'audio' => $this->audio() ? (string) $this->config->get('media-kit.video.audio_bitrate', '128k') : false,
            'version' => (int) $this->config->get('media-kit.video.version', 1),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Everything that decides how a poster looks.
     */
    public function posterProfile(): string
    {
        return json_encode([
            'max' => $this->maxDimension(),
            'at' => $this->posterAt(),
            'quality' => $this->posterQuality(),
            'version' => (int) $this->config->get('media-kit.video.version', 1),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: int, 1: int}  the copy's width and height
     */
    public function transcode(string $input, string $output, VideoInfo $info): array
    {
        [$width, $height] = $info->fitWithin($this->maxDimension());

        $arguments = [
            '-map', '0:v:0',
            '-c:v', 'libx264',
            '-preset', $this->preset(),
            '-crf', (string) $this->crf(),
            '-profile:v', 'high',
            '-pix_fmt', 'yuv420p',
        ];

        if (($lookahead = $this->lookahead()) > 0) {
            $arguments = [...$arguments, '-x264-params', "rc-lookahead={$lookahead}"];
        }

        if ($this->maxFps() > 0 && ($info->fps === null || $info->fps > $this->maxFps())) {
            $arguments = [...$arguments, '-fpsmax', (string) $this->maxFps()];
        }

        if ($this->audio() && $info->hasAudio) {
            $arguments = [
                ...$arguments,
                '-map', '0:a:0',
                '-c:a', 'aac',
                '-b:a', (string) $this->config->get('media-kit.video.audio_bitrate', '128k'),
                '-ac', '2',
            ];
        } else {
            $arguments[] = '-an';
        }

        $arguments = [
            ...$arguments,
            // Camera, time, GPS: nothing of it belongs in a published file.
            '-map_metadata', '-1',
            '-map_chapters', '-1',
            '-sn', '-dn',
            '-movflags', '+faststart',
            '-f', 'mp4',
        ];

        $this->runWithFilters($input, $output, $info, $width, $height, $arguments);

        return [$width, $height];
    }

    /**
     * One frame as a JPEG, at the size of the copy — at the moment chosen for
     * this video, or the configured one.
     *
     * @return array{0: int, 1: int}  the poster's width and height
     */
    public function poster(string $input, string $output, VideoInfo $info, ?float $chosen = null): array
    {
        [$width, $height] = $info->fitWithin($this->maxDimension());

        $at = match (true) {
            // A frame that exists: seeking to the very end gives none.
            $chosen !== null => $info->duration === null
                ? max(0.0, $chosen)
                : max(0.0, min($chosen, $info->duration - 0.1)),
            // Past a fade-in, but within a short clip: the middle at the latest.
            $info->duration === null => 0.0,
            default => max(0.0, min($this->posterAt(), $info->duration / 2)),
        };

        $this->runWithFilters($input, $output, $info, $width, $height, [
            '-frames:v', '1',
            '-map_metadata', '-1',
            '-q:v', (string) $this->jpegScale($this->posterQuality()),
            '-f', 'image2',
            '-update', '1',
        ], seek: $at);

        return [$width, $height];
    }

    /**
     * Run with tone mapping when the source is HDR, and again without it when
     * this build of ffmpeg has no `zscale`.
     *
     * @param  list<string>  $arguments
     */
    private function runWithFilters(
        string $input,
        string $output,
        VideoInfo $info,
        int $width,
        int $height,
        array $arguments,
        ?float $seek = null,
    ): void {
        if ($info->hdr && $info->transfer !== null) {
            try {
                $this->run($input, $output, $this->filters($width, $height, $info->transfer), $arguments, $seek);

                return;
            } catch (RuntimeException) {
                // Fall through to the plain chain below.
            }
        }

        $this->run($input, $output, $this->filters($width, $height, null), $arguments, $seek);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(string $input, string $output, string $filters, array $arguments, ?float $seek): void
    {
        $command = [$this->ffmpeg(), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y'];

        if ($seek !== null && $seek > 0) {
            // Before the input: seeks the container instead of decoding to it.
            $command = [...$command, '-ss', sprintf('%.3F', $seek)];
        }

        $threads = max(0, (int) $this->config->get('media-kit.video.threads', 0));

        // Decoder, filters and encoder each start their own threads, and each
        // holds frames: on a small worker one thread is both faster (they only
        // fight over the same core) and a fraction of the memory.
        if ($threads > 0) {
            $command = [...$command, '-threads', (string) $threads];
        }

        $command = [...$command, '-i', $input];

        if ($threads > 0) {
            $command = [...$command, '-filter_threads', (string) $threads, '-threads', (string) $threads];
        }

        $command = [...$command, '-vf', $filters];

        $process = new Process([...$command, ...$arguments, $output]);
        $process->setTimeout(max(60, (int) $this->config->get('media-kit.video.timeout', 1800)));
        $process->run();

        if (! $process->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('ffmpeg failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * Tone mapping (for an HDR source, from its transfer `$hdr`), scaling to
     * the copy's size and BT.709 — converted, then tagged.
     *
     * The tags go on the frames rather than on the encoder: ffmpeg takes the
     * colour of the frames over its own options, and an untagged copy is
     * guessed at differently by every browser.
     */
    private function filters(int $width, int $height, ?string $hdr): string
    {
        $filters = [];

        if ($hdr !== null) {
            // The source is named explicitly: zscale refuses a frame whose
            // tags were lost, and HDR is BT.2020 in practice.
            $filters[] = "zscale=tin={$hdr}:min=bt2020nc:pin=bt2020:t=linear:npl=100";
            $filters[] = 'format=gbrpf32le';
            $filters[] = 'zscale=p=bt709';
            $filters[] = 'tonemap=tonemap=hable:desat=0';
            $filters[] = 'zscale=t=bt709:m=bt709:r=tv';
        }

        // Rotation is applied by ffmpeg before the filters, so the size here
        // is already the upright one. An SD source in BT.601 is converted, not
        // merely relabelled.
        $filters[] = "scale={$width}:{$height}:flags=lanczos:out_color_matrix=bt709:out_range=tv";
        $filters[] = 'setsar=1';
        $filters[] = 'format=yuv420p';
        $filters[] = 'setparams=color_primaries=bt709:color_trc=bt709:colorspace=bt709:range=tv';

        return implode(',', $filters);
    }

    /**
     * JPEG quality 1–100 as ffmpeg's `-q:v` (2 best … 31 worst).
     */
    private function jpegScale(int $quality): int
    {
        $quality = max(1, min(100, $quality));

        return max(2, min(31, (int) round(31 - ($quality / 100) * 29)));
    }

    private function ffmpeg(): string
    {
        return (string) $this->config->get('media-kit.video.ffmpeg', 'ffmpeg');
    }

    private function maxDimension(): int
    {
        return max(0, (int) $this->config->get('media-kit.video.max_dimension', 1280));
    }

    private function crf(): int
    {
        return max(0, min(51, (int) $this->config->get('media-kit.video.crf', 26)));
    }

    private function preset(): string
    {
        $preset = (string) $this->config->get('media-kit.video.preset', 'medium');

        return in_array($preset, ['ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow'], true)
            ? $preset
            : 'medium';
    }

    private function maxFps(): int
    {
        return max(0, (int) $this->config->get('media-kit.video.max_fps', 30));
    }

    private function lookahead(): int
    {
        return max(0, (int) $this->config->get('media-kit.video.lookahead', 0));
    }

    private function audio(): bool
    {
        return (bool) $this->config->get('media-kit.video.audio', true);
    }

    private function posterAt(): float
    {
        return max(0.0, (float) $this->config->get('media-kit.video.poster_at', 1.0));
    }

    private function posterQuality(): int
    {
        return (int) $this->config->get('media-kit.video.poster_quality', 90);
    }
}
