<?php

declare(strict_types=1);

namespace Solo\Image\Internal;

use GdImage;
use Solo\Image\Exception\ImageException;
use Solo\Image\Exception\UnsupportedFormatException;
use Solo\Image\Format;

/**
 * Encodes pixels to bytes without changing the input image or the application's output buffer.
 *
 * @internal
 */
final class Encoder
{
    /**
     * An opaque source goes to JPEG as is and to PNG without an alpha channel.
     * Any other is composited on white for JPEG and keeps its alpha in PNG and WebP.
     */
    public static function encode(GdImage $source, Format $format, int $quality, bool $opaque): string
    {
        if ($quality < 0 || $quality > 100) {
            throw new \InvalidArgumentException('Quality must be between 0 and 100');
        }

        $codec = match ($format) {
            Format::Jpeg => IMG_JPG,
            Format::Png => IMG_PNG,
            Format::Webp => IMG_WEBP,
        };
        if ((imagetypes() & $codec) === 0) {
            throw new UnsupportedFormatException('GD cannot encode the requested format');
        }

        // php://memory, unlike php://temp, never spills large results to a temporary file.
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new ImageException('Could not open the encoded image stream');
        }

        $written = match ($format) {
            Format::Jpeg => @imagejpeg($opaque ? $source : self::onWhite($source), $stream, $quality),
            Format::Png => self::png($source, $stream, $opaque),
            Format::Webp => @imagewebp($source, $stream, $quality),
        };
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);

        // Some GD encoders report success even when no usable image was emitted, so the result is read back.
        $info = $written ? @getimagesizefromstring($bytes) : false;
        if (
            $info === false || $info['mime'] !== $format->value
            || $info[0] !== imagesx($source) || $info[1] !== imagesy($source)
        ) {
            throw new ImageException('Could not encode the image');
        }

        return $bytes;
    }

    /**
     * A constant alpha channel makes a PNG about 15% larger and a third slower to write, so an opaque
     * image is written as RGB. Only the flag changes, for the length of the call: the pixels stay as they are.
     *
     * @param resource $stream
     */
    private static function png(GdImage $source, $stream, bool $opaque): bool
    {
        imagesavealpha($source, !$opaque);
        try {
            return @imagepng($source, $stream, 6);
        } finally {
            imagesavealpha($source, true);
        }
    }

    private static function onWhite(GdImage $source): GdImage
    {
        $image = @imagecreatetruecolor(imagesx($source), imagesy($source));
        if ($image === false) {
            throw new ImageException('Could not allocate an image');
        }
        imagefilledrectangle($image, 0, 0, imagesx($source) - 1, imagesy($source) - 1, 0xFFFFFF);
        if (!imagecopy($image, $source, 0, 0, 0, 0, imagesx($source), imagesy($source))) {
            throw new ImageException('Could not flatten image transparency');
        }

        return $image;
    }
}
