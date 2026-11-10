<?php

declare(strict_types=1);

namespace Solo\Image\Tests;

use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Image\Format;
use Solo\Image\Image;

final class ContainTest extends TestCase
{
    public function testTheWholePictureIsCentredOnTransparentMargins(): void
    {
        $source = Image::fromBytes(self::opaque(120, 80, Format::Png));
        $pixels = self::pixels($source->contain(64, 64));

        // 120 × 80 fits as 64 × 42, eleven rows below the top.
        self::assertSame([64, 64], [imagesx($pixels), imagesy($pixels)]);
        self::assertSame(127, self::alpha($pixels, 32, 10));
        self::assertSame(0, self::alpha($pixels, 32, 11));
        self::assertSame(0, self::alpha($pixels, 0, 52));
        self::assertSame(127, self::alpha($pixels, 32, 53));
        self::assertSame([120, 80], [$source->width(), $source->height()]);
    }

    public function testSmallPicturesAreEnlargedUnlessUpscalingIsOff(): void
    {
        $source = Image::fromBytes(self::opaque(10, 5, Format::Png));

        $enlarged = self::pixels($source->contain(32, 32));
        self::assertSame(127, self::alpha($enlarged, 16, 7));
        self::assertSame(0, self::alpha($enlarged, 0, 8));
        self::assertSame(0, self::alpha($enlarged, 31, 23));
        self::assertSame(127, self::alpha($enlarged, 16, 24));

        $centred = self::pixels($source->contain(32, 32, upscale: false));
        self::assertSame(127, self::alpha($centred, 10, 13));
        self::assertSame(0, self::alpha($centred, 11, 13));
        self::assertSame(0, self::alpha($centred, 20, 17));
        self::assertSame(127, self::alpha($centred, 21, 17));
    }

    public function testAnImageOfTheExactSizeIsReturnedAsIs(): void
    {
        $source = Image::fromBytes(self::opaque(12, 8, Format::Png));

        self::assertSame($source, $source->contain(12, 8));
        $padded = $source->contain(30, 8, upscale: false);
        self::assertSame($padded, $padded->contain(30, 8));
    }

    public function testJpegMarginsBecomeWhiteAndPngMarginsTransparent(): void
    {
        $source = Image::fromBytes(self::opaque(16, 8, Format::Jpeg));

        $jpeg = imagecreatefromstring($source->contain(16, 16)->encode(Format::Jpeg, 100)->bytes);
        self::assertInstanceOf(GdImage::class, $jpeg);
        self::assertSame(0xFFFFFF, imagecolorat($jpeg, 8, 0));

        // Byte 25 of a PNG is its color type: 2 is RGB, 6 is RGBA.
        self::assertSame(6, ord($source->contain(16, 16)->encode(Format::Png)->bytes[25]));
        self::assertSame(2, ord($source->contain(8, 4)->encode(Format::Png)->bytes[25]));
    }

    public function testOrientationIsAppliedBeforeFitting(): void
    {
        $bytes = Fixtures::afterIhdr(self::opaque(120, 80, Format::Png), Fixtures::pngChunk('eXIf', Fixtures::tiff(6)));
        $pixels = self::pixels(Image::fromBytes($bytes)->contain(60, 60));

        // Turned upright the picture is 80 × 120, so it fits as 40 × 60, ten columns from the left.
        self::assertSame(127, self::alpha($pixels, 9, 30));
        self::assertSame(0, self::alpha($pixels, 10, 0));
        self::assertSame(0, self::alpha($pixels, 49, 59));
        self::assertSame(127, self::alpha($pixels, 50, 30));
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidSizes(): iterable
    {
        yield 'zero width' => [0, 10];
        yield 'negative height' => [10, -1];
    }

    #[DataProvider('invalidSizes')]
    public function testTheCanvasMustHaveAPositiveSize(int $width, int $height): void
    {
        $image = Image::fromBytes(self::opaque(4, 4, Format::Png));

        $this->expectException(\InvalidArgumentException::class);
        $image->contain($width, $height);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function opaque(int $width, int $height, Format $format): string
    {
        $canvas = Fixtures::canvas($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, 0x336699);

        return Fixtures::encode($canvas, $format);
    }

    private static function pixels(Image $image): GdImage
    {
        $pixels = imagecreatefromstring($image->encode(Format::Png)->bytes);
        self::assertInstanceOf(GdImage::class, $pixels);

        return $pixels;
    }

    private static function alpha(GdImage $pixels, int $x, int $y): int
    {
        $pixel = imagecolorat($pixels, $x, $y);
        self::assertIsInt($pixel);

        return $pixel >> 24;
    }
}
