<?php

namespace KandySoft\MediaKit\Contracts;

use KandySoft\MediaKit\Exceptions\MediaKeyMissing;

/**
 * Where the keys that wrap every file's own key come from.
 *
 * The default reads them from configuration (`ConfigKeyring`). A host that
 * keeps keys in a KMS or a vault binds its own implementation; nothing else in
 * the package changes, because files only ever name a key by its id.
 */
interface KeyProvider
{
    /**
     * Id of the key new files are wrapped with.
     *
     * @throws MediaKeyMissing
     */
    public function currentId(): string;

    /**
     * The raw 32-byte key with this id.
     *
     * @throws MediaKeyMissing
     */
    public function key(string $id): string;
}
