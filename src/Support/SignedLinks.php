<?php

namespace KandySoft\MediaKit\Support;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

/**
 * Signs the serving links and checks them on the way back in.
 *
 * By default a link is absolute and signed over the whole address, host included,
 * which is right when the application answers on the address its links carry.
 *
 * It is wrong behind anything that rewrites the address on the way in — a
 * backend-for-frontend proxying `/api/media/*` to the service's own `/media/*`, or
 * a gateway in front of an internal hostname. The request that arrives no longer
 * has the address that was signed, so every link comes back 403. Setting
 * `media-kit.routes.url` signs the path and query only, and puts that root in
 * front of the link instead: the client follows `/api/media/media/{uuid}?…`, the
 * proxy strips its prefix, and the signature still matches what arrives.
 *
 * Both halves live here so that signing and verifying cannot drift apart.
 */
final class SignedLinks
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly Config $config,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function sign(string $route, array $parameters, ?DateTimeInterface $expiration = null): string
    {
        $root = $this->root();

        if ($root === null) {
            return $this->urls->signedRoute($route, $parameters, $expiration);
        }

        return $root . $this->urls->signedRoute($route, $parameters, $expiration, absolute: false);
    }

    public function verify(Request $request): bool
    {
        return $this->root() === null
            ? $request->hasValidSignature()
            : $request->hasValidRelativeSignature();
    }

    /**
     * The configured root without a trailing slash; null when links are absolute.
     */
    private function root(): ?string
    {
        $root = $this->config->get('media-kit.routes.url');

        return is_string($root) && trim($root) !== '' ? rtrim(trim($root), '/') : null;
    }
}
