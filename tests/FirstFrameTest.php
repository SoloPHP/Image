<?php

declare(strict_types=1);

namespace Solo\Image\Tests;

use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Image\Exception\AnimatedImageException;
use Solo\Image\Exception\ImageTooLargeException;
use Solo\Image\Exception\InvalidImageException;
use Solo\Image\Format;
use Solo\Image\Image;
use Solo\Image\Limits;

final class FirstFrameTest extends TestCase
{
    public function testAnimatedPngGivesItsDefaultImage(): void
    {
        $canvas = Fixtures::canvas(10, 8);
        imagefilledrectangle($canvas, 0, 0, 9, 7, 0x336699);
        $png = Fixtures::encode($canvas, Format::Png);
        $control = Fixtures::pngChunk('acTL', pack('NN', 2, 0))
            . Fixtures::pngChunk('fcTL', pack('NNNNNnnCC', 0, 10, 8, 0, 0, 1, 10, 0, 0));
        $frame = Fixtures::pngChunk('fcTL', pack('NNNNNnnCC', 1, 4, 4, 0, 0, 1, 10, 0, 0))
            . Fixtures::pngChunk('fdAT', pack('N', 2) . 'not pixels');
        $bytes = substr(Fixtures::afterIhdr($png, $control), 0, -12) . $frame . substr($png, -12);

        $image = Image::fromBytes($bytes, firstFrame: true);
        self::assertSame([10, 8], [$image->width(), $image->height()]);
        $pixels = imagecreatefromstring($image->encode(Format::Png)->bytes);
        self::assertInstanceOf(GdImage::class, $pixels);
        self::assertSame(0x336699, imagecolorat($pixels, 9, 7));

        $this->expectException(AnimatedImageException::class);
        Image::fromBytes($bytes);
    }

    /** @return iterable<string, array{int, bool, int, int, string}> */
    public static function frames(): iterable
    {
        yield 'lossy' => [90, false, 2, 4, 'VP8 '];
        yield 'lossy with alpha' => [90, true, 2, 4, 'ALPH'];
        yield 'lossless' => [IMG_WEBP_LOSSLESS, true, 4, 0, 'VP8L'];
        yield 'full canvas' => [IMG_WEBP_LOSSLESS, false, 0, 0, 'VP8L'];
    }

    #[DataProvider('frames')]
    public function testAnimatedWebpGivesItsFirstFrameOnItsCanvas(
        int $quality,
        bool $alpha,
        int $x,
        int $y,
        string $chunk,
    ): void {
        [$width, $height] = $x === 0 && $y === 0 ? [20, 20] : [12, 8];
        $first = $alpha ? Fixtures::transparent($width, $height) : Fixtures::canvas($width, $height);
        imagefilledrectangle($first, 0, 0, $width - 1, $height - 2, 0xFF0000);
        $firstWebp = Fixtures::encode($first, Format::Webp, $quality);
        self::assertStringContainsString($chunk, $firstWebp);
        $second = Fixtures::canvas(20, 20);
        imagefilledrectangle($second, 0, 0, 19, 19, 0x0000FF);
        $secondWebp = Fixtures::encode($second, Format::Webp);
        $bytes = Fixtures::animatedWebp(20, 20, [[$firstWebp, $x, $y], [$secondWebp, 0, 0]]);

        $image = Image::fromBytes($bytes, firstFrame: true);
        self::assertSame([20, 20], [$image->width(), $image->height()]);
        $pixels = imagecreatefromstring($image->encode(Format::Png)->bytes);
        self::assertInstanceOf(GdImage::class, $pixels);

        $red = imagecolorat($pixels, $x + 3, $y + 2);
        self::assertIsInt($red);
        self::assertSame(0, $red >> 24);
        self::assertEqualsWithDelta(255, ($red >> 16) & 0xFF, 16);
        self::assertLessThan(40, $red & 0xFF);

        // The last row of the frame is black, or transparent when the frame has alpha.
        $lastRow = imagecolorat($pixels, $x + 1, $y + $height - 1);
        self::assertIsInt($lastRow);
        self::assertSame($alpha ? 127 : 0, $lastRow >> 24);
        if ($x > 0) {
            self::assertSame(127, (int) imagecolorat($pixels, 0, 0) >> 24);
            self::assertSame(127, (int) imagecolorat($pixels, 19, 19) >> 24);
        }
    }

    public function testAnimatedWebpIsRejectedByDefault(): void
    {
        $this->expectException(AnimatedImageException::class);
        Image::fromBytes(self::animation(10, 10, 0, 0));
    }

    public function testLimitsApplyToTheCanvasBeforeDecoding(): void
    {
        $this->expectException(ImageTooLargeException::class);
        Image::fromBytes(self::animation(10, 10, 0, 0), new Limits(maxPixels: 99), firstFrame: true);
    }

    public function testAFrameOutsideTheCanvasIsRejected(): void
    {
        $this->expectException(InvalidImageException::class);
        Image::fromBytes(self::animation(10, 10, 6, 0), firstFrame: true);
    }

    public function testAnAnimationWithoutFramesIsRejected(): void
    {
        $bytes = Fixtures::animatedWebp(10, 10, []);

        $this->expectException(InvalidImageException::class);
        Image::fromBytes($bytes, firstFrame: true);
    }

    public function testAFrameWithoutABitstreamIsRejected(): void
    {
        $header = str_repeat("\0", 9) . "\x03\0\0" . str_repeat("\0", 4);
        $bytes = Fixtures::animatedWebp(4, 4, []);
        $bytes .= Fixtures::webpChunk('ANMF', $header . Fixtures::webpChunk('ALPH', 'xx'));
        $bytes = 'RIFF' . pack('V', strlen($bytes) - 8) . substr($bytes, 8);

        $this->expectException(InvalidImageException::class);
        Image::fromBytes($bytes, firstFrame: true);
    }

    public function testStillImagesAreUnaffected(): void
    {
        $bytes = Fixtures::encode(Fixtures::canvas(6, 4), Format::Webp);

        self::assertSame(6, Image::fromBytes($bytes, firstFrame: true)->width());
    }

    /** An animation whose only frame, 6 × 4 pixels, lies at $x, $y. */
    private static function animation(int $width, int $height, int $x, int $y): string
    {
        $frame = Fixtures::encode(Fixtures::canvas(6, 4), Format::Webp);

        return Fixtures::animatedWebp($width, $height, [[$frame, $x, $y]]);
    }
}
