<?php

namespace KandySoft\MediaKit\Encryption;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Contracts\KeyProvider;
use KandySoft\MediaKit\Exceptions\MediaKeyMissing;

/**
 * Keys from configuration: `media-kit.encryption.keys` and `.current`.
 *
 * Keys are listed by id, so rotation is adding a key, making it current and
 * running `media:rekey` — old files stay readable throughout, because the old
 * key is still listed until the last file wrapped with it has been rewrapped.
 *
 * Read on every call rather than at construction: under a long-lived worker a
 * key captured once would outlive a rotation.
 */
final class ConfigKeyring implements KeyProvider
{
    public function __construct(private readonly Config $config) {}

    public function currentId(): string
    {
        $keys = $this->keys();
        $current = (string) $this->config->get('media-kit.encryption.current', '');

        if ($current !== '') {
            return isset($keys[$current]) ? $current : throw MediaKeyMissing::id($current);
        }

        // Without an explicit choice the last listed key is the newest one.
        $last = array_key_last($keys);

        return $last === null ? throw MediaKeyMissing::current() : (string) $last;
    }

    public function key(string $id): string
    {
        $encoded = $this->keys()[$id] ?? throw MediaKeyMissing::id($id);
        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw MediaKeyMissing::malformed($id);
        }

        return $key;
    }

    /**
     * `id:base64,id:base64` from the environment, or an array from a
     * published config file.
     *
     * @return array<string, string>
     */
    private function keys(): array
    {
        $configured = $this->config->get('media-kit.encryption.keys', []);

        if (is_array($configured)) {
            return array_map('strval', $configured);
        }

        $keys = [];

        foreach (explode(',', (string) $configured) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || ! str_contains($pair, ':')) {
                continue;
            }

            [$id, $key] = explode(':', $pair, 2);
            $keys[trim($id)] = trim($key);
        }

        return $keys;
    }
}
