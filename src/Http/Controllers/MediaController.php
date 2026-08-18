<?php

namespace KandySoft\MediaKit\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
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
    public function __construct(
        private readonly MediaFileRepository $media,
        private readonly MediaStorage $storage,
        private readonly MediaWriter $writer,
        private readonly Config $config,
    ) {}

    public function show(Request $request, string $uuid): Response
    {
        $media = $this->find($uuid);

        $this->guard($request, $media);

        return $this->serve($media);
    }

    public function download(Request $request, string $uuid): Response
    {
        $media = $this->find($uuid);

        $this->guard($request, $media);

        return $this->serve($media, disposition: 'attachment');
    }

    public function variant(Request $request, string $uuid, int $width, int $height, string $format): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'Invalid or expired signature.');

        $variant = $this->writer->ensureVariant($this->find($uuid), $width, $height, $format);

        return $this->serve($variant);
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
        abort_unless($media->is_public || $request->hasValidSignature(), 403, 'Invalid or expired signature.');
    }

    private function serve(MediaFile $media, string $disposition = 'inline'): Response
    {
        $disk = $this->storage->disk($media->disk);
        $url = $disk->url($media->path);

        if ($url !== null && $disposition === 'inline') {
            return redirect()->away($url)->withHeaders($this->cacheHeaders($media));
        }

        abort_unless($disk->exists($media->path), 404);

        $filename = $media->original_name ?? basename($media->path);

        return new StreamedResponse(
            function () use ($disk, $media): void {
                $stream = $disk->readStream($media->path);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $media->mime_type ?? 'application/octet-stream',
                'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, addslashes($filename)),
                'Content-Length' => (string) ($media->size ?? $disk->size($media->path) ?? 0),
            ] + $this->cacheHeaders($media),
        );
    }

    /**
     * How long the answer may be reused.
     *
     * A stored file never changes underneath its uuid — replacing a picture
     * creates a new record — so the bytes can be treated as immutable. Without
     * this every visit re-downloads pictures the browser already has, and a
     * CDN in front of the application caches nothing at all.
     *
     * A private file is a different matter on both counts. It belongs only in
     * the visitor's own cache, and it is reached through a link that expires,
     * so the answer must not outlive the link it points at.
     *
     * @return array<string, string>
     */
    private function cacheHeaders(MediaFile $media): array
    {
        $policy = $this->storage->disk($media->disk)->policy();

        if ($policy->cacheTtl <= 0) {
            return ['Cache-Control' => 'no-cache, must-revalidate'];
        }

        if (! $policy->public) {
            return ['Cache-Control' => sprintf(
                'private, max-age=%d',
                min($policy->cacheTtl, $policy->temporaryUrlTtl * 60),
            )];
        }

        return ['Cache-Control' => sprintf('public, max-age=%d, immutable', $policy->cacheTtl)];
    }
}
