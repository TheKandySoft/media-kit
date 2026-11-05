<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Data\VideoInfo;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads a video's size, length and colour with `ffprobe`.
 *
 * A probe reads the container's header, not the frames, so it costs a few
 * dozen milliseconds even for a long clip — cheap enough to run while the
 * upload is being stored, which is what gives a video its proportions from
 * the first moment instead of after it was encoded.
 *
 * Without `ffprobe` on the machine, or for a file it cannot read, the answer
 * is null and the video is stored without a size, as it always was.
 */
final class VideoProbe
{
    /** Transfer functions of HDR video: HLG (iPhone) and PQ (HDR10). */
    private const HDR_TRANSFERS = ['arib-std-b67', 'smpte2084'];

    public function __construct(private readonly Config $config) {}

    public function probe(string $path): ?VideoInfo
    {
        try {
            $process = new Process([
                (string) $this->config->get('media-kit.video.ffprobe', 'ffprobe'),
                '-v', 'error',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $path,
            ]);
            $process->setTimeout(60);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $data = json_decode($process->getOutput(), true);

        return is_array($data) ? self::fromProbe($data) : null;
    }

    /**
     * @param  array<string, mixed>  $data  `ffprobe -print_format json -show_format -show_streams`
     */
    public static function fromProbe(array $data): ?VideoInfo
    {
        $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $video = null;
        $hasAudio = false;

        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $type = $stream['codec_type'] ?? null;

            // A cover picture inside the container is a "video" stream too.
            if ($type === 'video' && $video === null && ! ($stream['disposition']['attached_pic'] ?? 0)) {
                $video = $stream;
            }

            if ($type === 'audio') {
                $hasAudio = true;
            }
        }

        $width = (int) ($video['width'] ?? 0);
        $height = (int) ($video['height'] ?? 0);

        if ($video === null || $width <= 0 || $height <= 0) {
            return null;
        }

        if (abs(self::rotation($video)) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $duration = $data['format']['duration'] ?? $video['duration'] ?? null;

        return new VideoInfo(
            width: $width,
            height: $height,
            duration: is_numeric($duration) && (float) $duration > 0 ? round((float) $duration, 3) : null,
            hasAudio: $hasAudio,
            hdr: in_array($video['color_transfer'] ?? null, self::HDR_TRANSFERS, true),
            fps: self::rate($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null),
            transfer: is_string($video['color_transfer'] ?? null) ? $video['color_transfer'] : null,
        );
    }

    /**
     * Degrees the frame is turned when shown — from the display matrix
     * (current ffmpeg), or the `rotate` tag older muxers wrote.
     *
     * @param  array<string, mixed>  $stream
     */
    private static function rotation(array $stream): int
    {
        foreach ((array) ($stream['side_data_list'] ?? []) as $side) {
            if (is_array($side) && isset($side['rotation']) && is_numeric($side['rotation'])) {
                return (int) round((float) $side['rotation']);
            }
        }

        $tag = $stream['tags']['rotate'] ?? null;

        return is_numeric($tag) ? (int) $tag : 0;
    }

    private static function rate(mixed $value): ?float
    {
        if (! is_string($value) || ! str_contains($value, '/')) {
            return null;
        }

        [$num, $den] = array_map('floatval', explode('/', $value, 2));

        return $num > 0 && $den > 0 ? round($num / $den, 3) : null;
    }
}
