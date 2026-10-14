<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;

/**
 * A new encryption key, ready to append to `MEDIA_KIT_KEYS`.
 *
 * Printed, not written anywhere: where the key lives — an `.env` on the
 * server, a secret store — is the host's decision, and a command that edited
 * files would make a copy of the key somewhere nobody chose.
 */
class GenerateMediaKey extends Command
{
    protected $signature = 'media:key {id? : Key id, defaults to today\'s date}';

    protected $description = 'Print a new media encryption key as id:base64';

    public function handle(): int
    {
        $id = (string) ($this->argument('id') ?: now()->format('Ymd'));

        if (! preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $id)) {
            $this->error('A key id is 1–64 letters, digits, dots, dashes or underscores.');

            return self::INVALID;
        }

        $this->line($id . ':' . base64_encode(sodium_crypto_secretbox_keygen()));
        $this->comment("Append it to MEDIA_KIT_KEYS and set MEDIA_KIT_KEY_CURRENT={$id}. Keep the old keys until `media:rekey` has run.");

        return self::SUCCESS;
    }
}
