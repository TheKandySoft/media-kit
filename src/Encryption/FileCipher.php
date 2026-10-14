<?php

namespace KandySoft\MediaKit\Encryption;

use KandySoft\MediaKit\Contracts\KeyProvider;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Exceptions\MediaTampered;
use RuntimeException;
use SodiumException;

/**
 * Envelope encryption of file contents, chunk by chunk.
 *
 * Every file gets its own random key; the file is encrypted with it as a
 * libsodium secretstream (XChaCha20-Poly1305) in 64 KiB chunks, and the key is
 * stored wrapped with the application's key. Three things follow:
 *
 * - **Nothing is loaded whole.** Chunks go from one stream to another, so a
 *   video costs the same memory as a thumbnail.
 * - **Tampering is caught, not decoded.** Each chunk is authenticated, chunks
 *   cannot be reordered, and a file cut short never reaches its final chunk.
 * - **Rotation touches no file.** A new application key only rewraps the
 *   per-file keys (`rewrap()`).
 */
final class FileCipher
{
    public const CHUNK = 65536;

    private const HEADER = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

    private const OVERHEAD = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

    public function __construct(private readonly KeyProvider $keys) {}

    /**
     * Sodium is bundled with PHP but can be compiled out; the package installs
     * without it and only refuses once encryption is actually used.
     */
    private static function ensureSodium(): void
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('Media encryption needs the sodium PHP extension.');
        }
    }

    /**
     * Encrypt everything readable from `$in` into `$out`.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    public function encrypt($in, $out): Envelope
    {
        self::ensureSodium();

        $fileKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);

        self::write($out, $header);

        $hash = hash_init('sha256');
        $size = 0;
        $chunk = self::read($in, self::CHUNK);

        do {
            $next = self::read($in, self::CHUNK);
            $final = $next === '';

            hash_update($hash, $chunk);
            $size += strlen($chunk);

            self::write($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                '',
                $final
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            ));

            $chunk = $next;
        } while (! $final);

        $keyId = $this->keys->currentId();
        $envelope = new Envelope(
            scheme: Envelope::SCHEME,
            keyId: $keyId,
            wrappedKey: $this->wrap($fileKey, $keyId),
            checksum: hash_final($hash),
            size: $size,
        );

        sodium_memzero($fileKey);

        return $envelope;
    }

    /**
     * Decrypt `$in` into `$out`, refusing anything altered or cut short.
     *
     * @param  resource  $in
     * @param  resource  $out
     *
     * @throws MediaTampered when a chunk fails or the final one never comes
     */
    public function decrypt($in, $out, Envelope $envelope, string $disk = '', string $path = ''): void
    {
        self::ensureSodium();

        $fileKey = $this->unwrap($envelope);

        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($in, self::HEADER), $fileKey);
        } catch (SodiumException) {
            throw MediaTampered::path($disk, $path);
        } finally {
            sodium_memzero($fileKey);
        }

        while (true) {
            $chunk = self::read($in, self::CHUNK + self::OVERHEAD);

            if ($chunk === '') {
                throw MediaTampered::path($disk, $path);
            }

            try {
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            } catch (SodiumException) {
                $pulled = false;
            }

            if ($pulled === false) {
                throw MediaTampered::path($disk, $path);
            }

            [$plain, $tag] = $pulled;

            self::write($out, $plain);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                return;
            }
        }
    }

    /**
     * The same file key, wrapped with another application key.
     */
    public function rewrap(Envelope $envelope, string $keyId): Envelope
    {
        $fileKey = $this->unwrap($envelope);
        $rewrapped = $envelope->withKey($keyId, $this->wrap($fileKey, $keyId));

        sodium_memzero($fileKey);

        return $rewrapped;
    }

    private function wrap(string $fileKey, string $keyId): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($fileKey, $nonce, $this->keys->key($keyId)));
    }

    private function unwrap(Envelope $envelope): string
    {
        $raw = base64_decode($envelope->wrappedKey, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new MediaTampered('The wrapped key of an encrypted file is unreadable.');
        }

        $fileKey = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->keys->key($envelope->keyId),
        );

        if ($fileKey === false) {
            throw new MediaTampered("The wrapped key of an encrypted file does not open with key [{$envelope->keyId}].");
        }

        return $fileKey;
    }

    /**
     * Exactly `$length` bytes, or fewer only at the end of the stream.
     *
     * A network stream hands out whatever has arrived, and a chunk shorter than
     * the format says would shift every chunk after it.
     *
     * @param  resource  $stream
     */
    private static function read($stream, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length && ! feof($stream)) {
            $part = fread($stream, $length - strlen($buffer));

            if ($part === false) {
                throw new RuntimeException('Reading a media stream failed.');
            }

            if ($part === '' && feof($stream)) {
                break;
            }

            $buffer .= $part;
        }

        return $buffer;
    }

    /**
     * @param  resource  $stream
     */
    private static function write($stream, string $bytes): void
    {
        if ($bytes !== '' && fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Writing a media stream failed.');
        }
    }
}
