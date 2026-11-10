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

    /**
     * Detects the format from bytes, applies EXIF orientation and enforces limits before raster decoding.
     * Animated PNG and WebP are rejected unless $firstFrame asks for their first frame as a still image.
     */
    public static function fromBytes(string $bytes, Limits $limits = new Limits(), bool $firstFrame = false): self
    {
        return new self(...Decoder::decode($bytes, $limits, $firstFrame));
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
     * An image that already fits is returned as is. $fast trades quality for speed on images decoded from JPEG
     * and is ignored for the others, which can be transparent.
     */
    public function resize(?int $width = null, ?int $height = null, bool $fast = false): self
    {
        [$fitWidth, $fitHeight] = Raster::fit($this->width(), $this->height(), $width, $height, false);

        return $this->scale($fitWidth, $fitHeight, $fast);
    }

    /**
     * Fits the whole picture inside exactly $width × $height, centred on transparent margins.
     * A smaller picture is enlarged unless $upscale is false. JPEG output shows the margins white.
     */
    public function contain(int $width, int $height, bool $upscale = true): self
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Canvas size must be positive');
        }
        [$fitWidth, $fitHeight] = Raster::fit($this->width(), $this->height(), $width, $height, $upscale);
        $image = $this->scale($fitWidth, $fitHeight);
        if ($fitWidth === $width && $fitHeight === $height) {
            return $image;
        }

        $pixels = Raster::turn($image->pixels, $image->turn);
        $x = intdiv($width - $fitWidth, 2);
        $y = intdiv($height - $fitHeight, 2);

        return new self(Raster::place($pixels, $width, $height, $x, $y), 0, false);
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

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function scale(int $width, int $height, bool $fast = false): self
    {
        if ($width === $this->width() && $height === $this->height()) {
            return $this;
        }

        // Pixels that lie on their side take the size the other way round.
        $pixels = $this->turn === 0
            ? Raster::resize($this->pixels, $width, $height, $fast && $this->opaque)
            : Raster::resize($this->pixels, $height, $width, $fast && $this->opaque);

        return new self(Raster::turn($pixels, $this->turn), 0, $this->opaque);
    }
}
