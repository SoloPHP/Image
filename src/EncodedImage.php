<?php

declare(strict_types=1);

namespace Solo\Image;

/** Encoded bytes and their format and pixel dimensions, ready for the caller's storage or response. */
final readonly class EncodedImage
{
    /** @internal Produced by Image::encode(). */
    public function __construct(
        public string $bytes,
        public Format $format,
        public int $width,
        public int $height,
    ) {
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    public function mimeType(): string
    {
        return $this->format->mimeType();
    }

    public function extension(): string
    {
        return $this->format->extension();
    }
}
