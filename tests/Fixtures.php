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

    /** WebP takes IMG_WEBP_LOSSLESS as its quality too. */
    public static function encode(GdImage $image, Format $format, int $quality = 90): string
    {
        $stream = fopen('php://memory', 'w+b');
        Assert::assertIsResource($stream);
        $written = match ($format) {
            Format::Jpeg => imagejpeg($image, $stream, $quality),
            Format::Png => imagepng($image, $stream),
            Format::Webp => imagewebp($image, $stream, $quality),
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

    public static function webpChunk(string $type, string $payload): string
    {
        return $type . pack('V', strlen($payload)) . $payload . (strlen($payload) % 2 === 1 ? "\0" : '');
    }

    /**
     * An animated WebP whose frames are still WebPs placed at even offsets. Its background color is opaque
     * magenta, which decoders are free to ignore and which must not show.
     *
     * @param list<array{string, int, int}> $frames Still WebP bytes and the frame's offset on the canvas.
     */
    public static function animatedWebp(int $width, int $height, array $frames): string
    {
        $chunks = self::webpChunk('VP8X', "\x12\0\0\0" . self::uint24($width - 1) . self::uint24($height - 1))
            . self::webpChunk('ANIM', pack('Vv', 0xFFFF00FF, 0));
        foreach ($frames as [$webp, $x, $y]) {
            $info = getimagesizefromstring($webp);
            Assert::assertIsArray($info);
            // The frame keeps every chunk of the still image but its VP8X header.
            $data = substr($webp, 12);
            if (str_starts_with($data, 'VP8X')) {
                $data = substr($data, 18);
            }
            $header = self::uint24(intdiv($x, 2)) . self::uint24(intdiv($y, 2))
                . self::uint24($info[0] - 1) . self::uint24($info[1] - 1) . self::uint24(100) . "\0";
            $chunks .= self::webpChunk('ANMF', $header . $data);
        }

        return 'RIFF' . pack('V', strlen($chunks) + 4) . 'WEBP' . $chunks;
    }

    private static function uint24(int $value): string
    {
        return substr(pack('V', $value), 0, 3);
    }
}
