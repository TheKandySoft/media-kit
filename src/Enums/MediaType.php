<?php

namespace KandySoft\MediaKit\Enums;

/**
 * What kind of file we are dealing with, derived from its extension.
 *
 * Only these types can be uploaded — an unknown extension is rejected rather
 * than stored as "unknown", so nothing ends up in the library that the package
 * cannot describe.
 */
enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';
    case Audio = 'audio';
    case Archive = 'archive';
    case Vector = 'vector';

    /**
     * Extensions per type, in resolution order.
     *
     * `pdf` appears under both Document and Vector; Document wins because that
     * is how a PDF behaves for everything this package does with it.
     *
     * @return array<string, array<int, string>>
     */
    public static function extensionMap(): array
    {
        return [
            self::Image->value => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'avif'],
            self::Video->value => ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv'],
            self::Document->value => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
            self::Audio->value => ['mp3', 'wav', 'ogg', 'aac', 'flac'],
            self::Archive->value => ['zip', 'rar', 'tar', 'gz', '7z'],
            self::Vector->value => ['svg', 'eps'],
        ];
    }

    public static function fromExtension(string $extension): ?self
    {
        $extension = strtolower(ltrim($extension, '.'));

        foreach (self::extensionMap() as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return self::from($type);
            }
        }

        return null;
    }

    public static function supports(string $extension): bool
    {
        return self::fromExtension($extension) !== null;
    }

    public function isImage(): bool
    {
        return $this === self::Image;
    }

    /**
     * @return array<int, string>
     */
    public function extensions(): array
    {
        return self::extensionMap()[$this->value];
    }
}
