<?php

declare(strict_types=1);

namespace Solo\Image;

/** The still-image formats supported for reading and encoding. */
enum Format: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';

    public function mimeType(): string
    {
        return $this->value;
    }

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Png => 'png',
            self::Webp => 'webp',
        };
    }
}
