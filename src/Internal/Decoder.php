<?php

declare(strict_types=1);

namespace Solo\Image\Internal;

use GdImage;
use Solo\Image\Exception\ImageTooLargeException;
use Solo\Image\Exception\InvalidImageException;
use Solo\Image\Exception\UnsupportedFormatException;
use Solo\Image\Format;
use Solo\Image\Limits;

/**
 * Enforces input limits and decodes a still image, or the first frame of an animation, mirrored as its
 * orientation asks. A WebP frame smaller than its canvas is placed on a transparent canvas.
 *
 * @internal
 */
final class Decoder
{
    /**
     * @return array{GdImage, int, bool} The pixels, the quarter turn they still need, in degrees,
     *                                   and whether the format rules out transparency.
     */
    public static function decode(string $bytes, Limits $limits, bool $firstFrame = false): array
    {
        if (strlen($bytes) > $limits->maxBytes) {
            throw new ImageTooLargeException("Image exceeds the limit of {$limits->maxBytes} bytes");
        }

        $metadata = Metadata::inspect($bytes, $firstFrame);
        // Division avoids overflow from multiplying untrusted dimensions.
        if ($metadata->width > intdiv($limits->maxPixels, $metadata->height)) {
            throw new ImageTooLargeException("Image exceeds the limit of {$limits->maxPixels} pixels");
        }

        $codec = match ($metadata->format) {
            Format::Jpeg => IMG_JPG,
            Format::Png => IMG_PNG,
            Format::Webp => IMG_WEBP,
        };
        if ((imagetypes() & $codec) === 0) {
            throw new UnsupportedFormatException('GD cannot decode the image format');
        }

        $frame = $metadata->frame;
        $image = @imagecreatefromstring($frame['bytes'] ?? $bytes);
        if ($image === false) {
            throw new InvalidImageException('Cannot decode the image pixels');
        }

        $image = Raster::normalize($image);
        if ($frame !== null && ($frame['width'] !== $metadata->width || $frame['height'] !== $metadata->height)) {
            $image = Raster::place($image, $metadata->width, $metadata->height, $frame['x'], $frame['y']);
        }

        return [$image, Raster::mirror($image, $metadata->orientation), $metadata->format === Format::Jpeg];
    }
}
