<?php

declare(strict_types=1);

namespace Solo\Image\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Image\Exception\AnimatedImageException;
use Solo\Image\Exception\InvalidImageException;
use Solo\Image\Exception\UnsupportedFormatException;
use Solo\Image\Format;
use Solo\Image\Internal\ExifOrientation;
use Solo\Image\Internal\Metadata;

final class MetadataTest extends TestCase
{
    /** @return iterable<string, array{Format, int}> */
    public static function orientations(): iterable
    {
        foreach (Format::cases() as $format) {
            for ($orientation = 1; $orientation <= 8; $orientation++) {
                yield $format->name . '-' . $orientation => [$format, $orientation];
            }
        }
    }

    #[DataProvider('orientations')]
    public function testReadsOrientationFromEachContainer(Format $format, int $orientation): void
    {
        $bytes = $this->withExif($format, Fixtures::tiff($orientation));
        $metadata = Metadata::inspect($bytes);

        self::assertSame(3, $metadata->width);
        self::assertSame(2, $metadata->height);
        self::assertSame($format, $metadata->format);
        self::assertSame($orientation, $metadata->orientation);
    }

    public function testReadsBigEndianTiff(): void
    {
        $tiff = "MM\0*" . pack('N', 8) . pack('n', 1)
            . pack('nnN', 0x0112, 3, 1) . pack('n', 7) . "\0\0" . pack('N', 0);

        self::assertSame(7, ExifOrientation::read($tiff));
    }

    public function testAcceptsExifPrefixFromWebpWriters(): void
    {
        $metadata = Metadata::inspect($this->withExif(Format::Webp, "Exif\0\0" . Fixtures::tiff(6)));

        self::assertSame(6, $metadata->orientation);
    }

    public function testMissingMalformedAndOutOfRangeExifHaveDefaultOrientation(): void
    {
        foreach (Format::cases() as $format) {
            self::assertSame(1, Metadata::inspect($this->image($format))->orientation);
            self::assertSame(1, Metadata::inspect($this->withExif($format, 'broken'))->orientation);
            self::assertSame(1, Metadata::inspect($this->withExif($format, Fixtures::tiff(9)))->orientation);
        }
        self::assertSame(1, ExifOrientation::read(''));
    }

    public function testRejectsAnimationChunksInWebp(): void
    {
        $bytes = $this->image(Format::Webp);
        $payload = substr($bytes, 8) . self::webpChunk('ANIM', str_repeat("\0", 6));
        $bytes = self::riff($payload);

        $this->expectException(AnimatedImageException::class);
        Metadata::inspect($bytes);
    }

    public function testRejectsWebpAnimationFlagEvenWithoutAnimationChunks(): void
    {
        $vp8x = self::webpChunk('VP8X', "\x02\0\0\0\x02\0\0\x01\0\0");
        $payload = 'WEBP' . $vp8x . substr($this->image(Format::Webp), 12);
        $bytes = self::riff($payload);

        $this->expectException(AnimatedImageException::class);
        Metadata::inspect($bytes);
    }

    public function testRejectsAnimatedPng(): void
    {
        $bytes = Fixtures::afterIhdr($this->image(Format::Png), Fixtures::pngChunk('acTL', pack('NN', 2, 0)));

        $this->expectException(AnimatedImageException::class);
        Metadata::inspect($bytes);
    }

    public function testRejectsTruncatedWebpChunks(): void
    {
        $bytes = $this->image(Format::Webp);
        $payload = substr($bytes, 8) . 'EXIF' . pack('V', 100) . 'short';
        $bytes = self::riff($payload);

        $this->expectException(InvalidImageException::class);
        Metadata::inspect($bytes);
    }

    public function testRejectsTruncatedPngChunks(): void
    {
        $bytes = $this->image(Format::Png);
        $bytes = substr($bytes, 0, 33) . pack('N', 1000000) . 'eXIf' . 'short';

        $this->expectException(InvalidImageException::class);
        Metadata::inspect($bytes);
    }

    public function testSkipsEmptyAndOddSizedWebpChunksWithoutLosingExif(): void
    {
        $bytes = $this->image(Format::Webp);
        $payload = substr($bytes, 8)
            . self::webpChunk('ZERO', '')
            . self::webpChunk('ODD!', 'x')
            . self::webpChunk('EXIF', Fixtures::tiff(5));
        $bytes = self::riff($payload);

        self::assertSame(5, Metadata::inspect($bytes)->orientation);
    }

    public function testAcceptsWebpWithoutPaddingAfterTheLastChunk(): void
    {
        $payload = substr($this->image(Format::Webp), 8) . 'EXIF' . pack('V', 27) . Fixtures::tiff(8) . "\0";
        $bytes = self::riff($payload);

        self::assertSame(8, Metadata::inspect($bytes)->orientation);
    }

    public function testRejectsGifAsUnsupported(): void
    {
        $gif = (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        $this->expectException(UnsupportedFormatException::class);
        Metadata::inspect($gif);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidImages(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['This is not an image'];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'];
    }

    #[DataProvider('invalidImages')]
    public function testRejectsUnrecognizedBytes(string $bytes): void
    {
        $this->expectException(InvalidImageException::class);
        Metadata::inspect($bytes);
    }

    private function image(Format $format): string
    {
        return Fixtures::encode(Fixtures::canvas(3, 2), $format);
    }

    private function withExif(Format $format, string $tiff): string
    {
        $bytes = $this->image($format);

        return match ($format) {
            Format::Jpeg => substr($bytes, 0, 2) . "\xff\xe1" . pack('n', strlen($tiff) + 8)
                . "Exif\0\0" . $tiff . substr($bytes, 2),
            Format::Png => Fixtures::afterIhdr($bytes, Fixtures::pngChunk('eXIf', $tiff)),
            Format::Webp => self::webpWithExif($bytes, $tiff),
        };
    }

    private static function webpWithExif(string $bytes, string $tiff): string
    {
        $vp8x = self::webpChunk('VP8X', "\x08\0\0\0\x02\0\0\x01\0\0");
        $payload = 'WEBP' . $vp8x . substr($bytes, 12) . self::webpChunk('EXIF', $tiff);

        return self::riff($payload);
    }

    /** Wraps everything after the RIFF size field, starting with "WEBP", in a RIFF header. */
    private static function riff(string $payload): string
    {
        return 'RIFF' . pack('V', strlen($payload)) . $payload;
    }

    private static function webpChunk(string $type, string $payload): string
    {
        return $type . pack('V', strlen($payload)) . $payload . (strlen($payload) % 2 === 1 ? "\0" : '');
    }
}
