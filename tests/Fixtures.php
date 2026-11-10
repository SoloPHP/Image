<?php

declare(strict_types=1);

namespace Solo\Image\Tests;

use GdImage;
use PHPUnit\Framework\Assert;
use Solo\Image\Format;

/** Images and container parts built with GD alone, so fixtures do not depend on the code under test. */
final class Fixtures
{
    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        Assert::assertInstanceOf(GdImage::class, $image);

        return $image;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function transparent(int $width, int $height): GdImage
    {
        $image = self::canvas($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, 0x7F000000);

        return $image;
    }

    public static function encode(GdImage $image, Format $format): string
    {
        $stream = fopen('php://memory', 'w+b');
        Assert::assertIsResource($stream);
        $written = match ($format) {
            Format::Jpeg => imagejpeg($image, $stream, 90),
            Format::Png => imagepng($image, $stream),
            Format::Webp => imagewebp($image, $stream, 90),
        };
        Assert::assertTrue($written);
        rewind($stream);
        $bytes = stream_get_contents($stream);
        Assert::assertIsString($bytes);

        return $bytes;
    }

    /** A little-endian TIFF block whose only tag is the orientation. */
    public static function tiff(int $orientation): string
    {
        return "II*\0" . pack('V', 8) . pack('v', 1)
            . pack('vvV', 0x0112, 3, 1) . pack('v', $orientation) . "\0\0" . pack('V', 0);
    }

    public static function pngChunk(string $type, string $payload): string
    {
        return pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
    }

    /** Puts chunks right after IHDR, which ends at byte 33 of every PNG. */
    public static function afterIhdr(string $png, string $chunks): string
    {
        return substr($png, 0, 33) . $chunks . substr($png, 33);
    }
}
