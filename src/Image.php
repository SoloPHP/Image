<?php

declare(strict_types=1);

namespace Solo\Image;

use GdImage;
use Solo\Image\Internal\Decoder;
use Solo\Image\Internal\Encoder;
use Solo\Image\Internal\Raster;

/** An immutable still image. Its pixels never change, so one decoded source can produce many variants. */
final readonly class Image
{
    /**
     * A quarter turn for EXIF orientation is expensive on a full-size photo, so it stays pending in $turn,
     * in degrees, until it can be done on a resized copy. Until then the pixels lie on their side.
     */
    private function __construct(private GdImage $pixels, private int $turn, private bool $opaque)
    {
    }

    /** Detects the format from bytes, applies EXIF orientation and enforces limits before raster decoding. */
    public static function fromBytes(string $bytes, Limits $limits = new Limits()): self
    {
        return new self(...Decoder::decode($bytes, $limits));
    }

    public function width(): int
    {
        return $this->turn === 0 ? imagesx($this->pixels) : imagesy($this->pixels);
    }

    public function height(): int
    {
        return $this->turn === 0 ? imagesy($this->pixels) : imagesx($this->pixels);
    }

    /**
     * Fits within one or both bounds, preserving aspect ratio and never enlarging the source.
     * An image that already fits is returned as is.
     */
    public function resize(?int $width = null, ?int $height = null): self
    {
        // Pixels that lie on their side take the bounds the other way round.
        $pixels = $this->turn === 0
            ? Raster::resize($this->pixels, $width, $height)
            : Raster::resize($this->pixels, $height, $width);

        return $pixels === $this->pixels ? $this : new self(Raster::turn($pixels, $this->turn), 0, $this->opaque);
    }

    /**
     * Quality is 0..100 for JPEG and WebP. PNG uses lossless compression at level 6.
     * JPEG composites transparency on white. Re-encoding removes source metadata.
     */
    public function encode(Format $format = Format::Webp, int $quality = 82): EncodedImage
    {
        $pixels = Raster::turn($this->pixels, $this->turn);

        return new EncodedImage(
            Encoder::encode($pixels, $format, $quality, $this->opaque),
            $format,
            imagesx($pixels),
            imagesy($pixels),
        );
    }
}
