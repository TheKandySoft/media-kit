<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use KandySoft\MediaKit\Enums\MediaType;

/**
 * Decides what type a file is, with the host's own extensions taken into account.
 *
 * `MediaType::extensionMap()` is what the package knows out of the box, and it is a
 * catalogue's view of the world. An accounting system receives XML declarations,
 * CSV statements and detached `.p7s` signatures; refusing those because the list
 * was written for a shop is not a decision the package should make for its host.
 *
 * Configured extensions are added to a type, never moved out of one: the built-in
 * list resolves first, so configuration cannot quietly turn a picture into a
 * document and switch its variants off.
 */
final class MediaTypeResolver
{
    public function __construct(private readonly Config $config) {}

    public function fromExtension(string $extension): ?MediaType
    {
        $extension = self::normalise($extension);

        foreach ($this->extensionMap() as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return MediaType::from($type);
            }
        }

        return null;
    }

    public function supports(string $extension): bool
    {
        return $this->fromExtension($extension) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function extensions(MediaType $type): array
    {
        return $this->extensionMap()[$type->value];
    }

    /**
     * The built-in map with the configured extensions appended to each type.
     *
     * @return array<string, array<int, string>>
     */
    public function extensionMap(): array
    {
        $map = MediaType::extensionMap();

        foreach ((array) $this->config->get('media-kit.types', []) as $type => $extensions) {
            if (MediaType::tryFrom((string) $type) === null) {
                throw new InvalidArgumentException(sprintf(
                    'media-kit.types names "%s", which is not a media type. Known types: %s.',
                    $type,
                    implode(', ', array_map(static fn(MediaType $case): string => $case->value, MediaType::cases())),
                ));
            }

            $map[$type] = array_values(array_unique([
                ...$map[$type],
                ...array_map(self::normalise(...), (array) $extensions),
            ]));
        }

        return $map;
    }

    private static function normalise(string $extension): string
    {
        return strtolower(ltrim($extension, '.'));
    }
}
