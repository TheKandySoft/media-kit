<?php

namespace KandySoft\MediaKit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Support\SignedLinks;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving endpoints.
 *
 * Public files are handed off to the disk with a redirect. Private files are
 * streamed by the application and demand a valid signature — as do variants,
 * which would otherwise let anyone fill the disk with arbitrary sizes.
 */
class MediaController extends Controller
{
    /**
     * Types a browser executes rather than displays.
     *
     * @var array<int, string>
     */
    private const array ACTIVE_CONTENT = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
        'text/javascript',
        'application/javascript',
    ];

    public function __construct(
        private readonly MediaFileRepository $media,
        private readonly MediaStorage $storage,
        private readonly MediaWriter $writer,
        private readonly SignedLinks $links,
    ) {}

    public function show(Request $request, string $uuid): Response
    {
        $media = $this->find($uuid);

        $this->guard($request, $media);

        return $this->serve($request, $media);
    }

    public function download(Request $request, string $uuid): Response
    {
        $media = $this->find($uuid);

        $this->guard($request, $media);

        return $this->serve($request, $media, disposition: 'attachment');
    }

    public function variant(Request $request, string $uuid, int $width, int $height, string $format): Response
    {
        abort_unless($this->links->verify($request), 403, 'Invalid or expired signature.');

        $variant = $this->writer->ensureVariant($this->find($uuid), $width, $height, $format);

        return $this->serve($request, $variant);
    }

    private function find(string $uuid): MediaFile
    {
        return $this->media->findByUuid($uuid) ?? abort(404);
    }

    /**
     * A public file is public. Anything else needs a signed link.
     */
    private function guard(Request $request, MediaFile $media): void
    {
        abort_unless($media->is_public || $this->links->verify($request), 403, 'Invalid or expired signature.');
    }

    private function serve(Request $request, MediaFile $media, string $disposition = 'inline'): Response
    {
        $disk = $this->storage->disk($media->disk);
        $url = $disk->url($media->path, sealed: $media->encryption !== null);
        $headers = $this->cacheHeaders($request, $media);

        if ($url !== null && $disposition === 'inline') {
            return redirect()->away($url)->withHeaders($headers);
        }

        abort_unless($disk->exists($media->path), 404);

        $etag = $this->etag($media);
        $headers['ETag'] = $etag;

        // A short-lived file cannot be cached for long, so it comes back often.
        // Answering "still the same" in a few bytes is the difference between
        // that and streaming the whole thing again on every visit.
        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        $filename = $media->original_name ?? basename($media->path);
        $mimeType = $media->mime_type ?? 'application/octet-stream';

        // Content a browser would run is never shown in place: opened from the
        // application's own origin it would run with the application's rights.
        if (in_array(strtolower($mimeType), self::ACTIVE_CONTENT, true)) {
            $disposition = 'attachment';
        }

        $size = (int) ($media->size ?? $disk->size($media->path) ?? 0);
        $range = $this->range($request, $size, $etag);

        if ($range === false) {
            return response('', 416, ['Content-Range' => "bytes */{$size}"] + $headers);
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $length = max(0, $end - $start + 1);

        $headers += [
            'Content-Type' => $mimeType,
            // Without it a browser second-guesses the type from the bytes,
            // which is exactly what the check above is there to prevent.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, addslashes($filename)),
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
        ];

        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return new StreamedResponse(
            function () use ($disk, $media, $start, $length): void {
                // Decrypted on the way out when stored encrypted; the size is
                // the plaintext's, which the record keeps.
                $stream = $disk->openStream($media->path, Envelope::of($media));

                try {
                    self::skip($stream, $start);
                    self::pass($stream, $length);
                } finally {
                    fclose($stream);
                }
            },
            $range === null ? 200 : 206,
            $headers,
        );
    }

    /**
     * The one byte range asked for, null for the whole file, false when it
     * cannot be satisfied.
     *
     * A video player and a PDF viewer ask for pieces: without ranges a seek
     * in a video downloads it from the start, and on a phone that is the whole
     * file before the first frame. One range covers every player in use; a
     * request for several gets the whole file, which the standard allows.
     *
     * @return array{0: int, 1: int}|false|null
     */
    private function range(Request $request, int $size, string $etag): array|false|null
    {
        $header = (string) $request->headers->get('Range', '');

        if ($header === '' || $size <= 0 || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match)) {
            return null;
        }

        // A range of a version the client no longer has would splice two files.
        $ifRange = (string) $request->headers->get('If-Range', '');

        if ($ifRange !== '' && $ifRange !== $etag) {
            return null;
        }

        if ($match[1] === '' && $match[2] === '') {
            return null;
        }

        if ($match[1] === '') {
            // The last N bytes.
            $start = max(0, $size - (int) $match[2]);
            $end = $size - 1;
        } else {
            $start = (int) $match[1];
            $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
        }

        return $start > $end || $start >= $size ? false : [$start, $end];
    }

    /**
     * @param  resource  $stream
     */
    private static function skip($stream, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $meta = stream_get_meta_data($stream);

        if (($meta['seekable'] ?? false) && fseek($stream, $bytes) === 0) {
            return;
        }

        // An object store's stream does not seek: read past what was not asked for.
        while ($bytes > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(65536, $bytes));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $bytes -= strlen($chunk);
        }
    }

    /**
     * @param  resource  $stream
     */
    private static function pass($stream, int $bytes): void
    {
        while ($bytes > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(65536, $bytes));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $bytes -= strlen($chunk);
        }
    }

    /**
     * A validator for the bytes behind this uuid.
     *
     * Weak, because two responses for the same version are equivalent without
     * being byte-identical — the disposition differs between viewing and
     * downloading.
     */
    private function etag(MediaFile $media): string
    {
        return sprintf('W/"%s-%d"', $media->uuid, $media->updated_at?->getTimestamp() ?? 0);
    }

    /**
     * How long the answer may be reused.
     *
     * A stored file never changes underneath its address — replacing a picture
     * moves it to a new path and bumps the version the address carries — so the
     * bytes can be treated as immutable. Without this every visit re-downloads
     * pictures the browser already has, and a CDN in front of the application
     * caches nothing at all.
     *
     * The one thing an answer must never outlive is the link that produced it,
     * and the link says so itself: a signed address carries its own expiry.
     * Reading that instead of the configuration is what keeps the two from
     * drifting apart — a link deliberately shortened for one caller cannot end
     * up described by the disk's year-long default.
     *
     * @return array<string, string>
     */
    private function cacheHeaders(Request $request, MediaFile $media): array
    {
        $seconds = $this->storage->disk($media->disk)->policy()->cacheTtl;

        if ($seconds <= 0) {
            return ['Cache-Control' => 'no-cache, must-revalidate'];
        }

        $scope = $media->is_public ? 'public' : 'private';
        $expires = (int) $request->query('expires', 0);

        if ($expires <= 0) {
            return ['Cache-Control' => sprintf('%s, max-age=%d, immutable', $scope, $seconds)];
        }

        $remaining = max(0, $expires - now()->getTimestamp());

        return ['Cache-Control' => sprintf('%s, max-age=%d', $scope, min($seconds, $remaining))];
    }
}
