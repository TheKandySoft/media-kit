<?php

namespace KandySoft\MediaKit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
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
        $url = $disk->url($media->path);
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

        return new StreamedResponse(
            function () use ($disk, $media): void {
                $stream = $disk->readStream($media->path);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $mimeType,
                // Without it a browser second-guesses the type from the bytes,
                // which is exactly what the check above is there to prevent.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, addslashes($filename)),
                'Content-Length' => (string) ($media->size ?? $disk->size($media->path) ?? 0),
            ] + $headers,
        );
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
