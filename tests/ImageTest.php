<?php

declare(strict_types=1);

namespace Solo\Image\Tests;

use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Image\Exception\AnimatedImageException;
use Solo\Image\Exception\ImageException;
use Solo\Image\Exception\ImageTooLargeException;
use Solo\Image\Exception\InvalidImageException;
use Solo\Image\Format;
use Solo\Image\Image;
use Solo\Image\Limits;
use Solo\Image\Optimizer;

final class ImageTest extends TestCase
{
    /** @return iterable<string, array{Format}> */
    public static function formats(): iterable
    {
        foreach (Format::cases() as $format) {
            yield $format->name => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testSupportedFormatsCanBeDecodedResizedAndEncoded(Format $format): void
    {
        $input = Image::fromBytes(self::png(120, 80))->encode($format);
        $output = Image::fromBytes($input->bytes)->resize(width: 60)->encode($format);
        $decoded = Image::fromBytes($output->bytes);

        self::assertSame([60, 40], [$decoded->width(), $decoded->height()]);
        self::assertSame($format, $output->format);
    }

    public function testOneSourceProducesIndependentVariants(): void
    {
        $source = Image::fromBytes(self::png(120, 80));
        $small = $source->resize(width: 30)->encode(Format::Png);
        $large = $source->resize(height: 40)->encode(Format::Webp);

        self::assertSame([120, 80], [$source->width(), $source->height()]);
        self::assertSame([30, 20], [$small->width, $small->height]);
        self::assertSame([60, 40], [$large->width, $large->height]);
        self::assertSame(Format::Png, $small->format);
        self::assertSame('image/png', $small->mimeType());
        self::assertSame('png', $small->extension());
        self::assertSame(strlen($small->bytes), $small->size());
        self::assertSame([30, 20], array_slice(self::dimensions($small->bytes), 0, 2));
        self::assertSame([60, 40], array_slice(self::dimensions($large->bytes), 0, 2));
    }

    public function testExifIsAppliedBeforeResizingAndRemovedFromOutput(): void
    {
        $bytes = Fixtures::afterIhdr(self::png(120, 80), Fixtures::pngChunk('eXIf', Fixtures::tiff(6)));

        $image = Image::fromBytes($bytes);
        self::assertSame([80, 120], [$image->width(), $image->height()]);
        $encoded = $image->resize(height: 60)->encode(Format::Png);
        self::assertSame([40, 60], [$encoded->width, $encoded->height]);
        self::assertStringNotContainsString('eXIf', $encoded->bytes);
        $again = Image::fromBytes($encoded->bytes);
        self::assertSame([40, 60], [$again->width(), $again->height()]);
    }

    /**
     * The source has a red, green, blue and yellow quadrant, clockwise from the top left: red, green,
     * yellow, blue. The colors expected in the top left and top right tell all eight orientations apart.
     *
     * @return iterable<string, array{int, int, int}>
     */
    public static function orientations(): iterable
    {
        [$red, $green, $blue, $yellow] = [0xFF0000, 0x00FF00, 0x0000FF, 0xFFFF00];

        yield 'normal' => [1, $red, $green];
        yield 'horizontal mirror' => [2, $green, $red];
        yield 'half turn' => [3, $yellow, $blue];
        yield 'vertical mirror' => [4, $blue, $yellow];
        yield 'transpose' => [5, $red, $blue];
        yield 'clockwise' => [6, $blue, $red];
        yield 'transverse' => [7, $yellow, $green];
        yield 'counterclockwise' => [8, $green, $yellow];
    }

    #[DataProvider('orientations')]
    public function testEveryOrientationShowsTheSamePictureAtFullSizeAndResized(
        int $orientation,
        int $topLeft,
        int $topRight,
    ): void {
        $canvas = Fixtures::canvas(40, 20);
        foreach ([[0, 0, 0xFF0000], [20, 0, 0x00FF00], [0, 10, 0x0000FF], [20, 10, 0xFFFF00]] as [$x, $y, $color]) {
            imagefilledrectangle($canvas, $x, $y, $x + 19, $y + 9, $color);
        }
        $png = Fixtures::encode($canvas, Format::Png);
        $image = Image::fromBytes(Fixtures::afterIhdr($png, Fixtures::pngChunk('eXIf', Fixtures::tiff($orientation))));

        $size = $orientation < 5 ? [40, 20] : [20, 40];
        self::assertSame($size, [$image->width(), $image->height()]);
        self::assertSame($image, $image->resize(width: $size[0], height: $size[1]));

        $full = $image->encode(Format::Png);
        $half = $image->resize(width: intdiv($size[0], 2))->encode(Format::Png);
        self::assertSame($size, [$full->width, $full->height]);
        self::assertSame([intdiv($size[0], 2), intdiv($size[1], 2)], [$half->width, $half->height]);

        $fullPixels = imagecreatefromstring($full->bytes);
        $halfPixels = imagecreatefromstring($half->bytes);
        self::assertInstanceOf(GdImage::class, $fullPixels);
        self::assertInstanceOf(GdImage::class, $halfPixels);
        foreach ([[$full, $fullPixels], [$half, $halfPixels]] as [$encoded, $pixels]) {
            $right = $encoded->width - 1;
            self::assertSame($topLeft, imagecolorat($pixels, 0, 0));
            self::assertSame($topRight, imagecolorat($pixels, $right, 0));
        }
    }

    public function testAnImageThatAlreadyFitsIsReturnedAsIs(): void
    {
        $source = Image::fromBytes(self::png(120, 80));

        self::assertSame($source, $source->resize(width: 120));
        self::assertSame($source, $source->resize(width: 500, height: 500));
        self::assertNotSame($source, $source->resize(width: 119));
    }

    public function testFastResizeHasTheSameSizeAsTheDefault(): void
    {
        $jpeg = Image::fromBytes(Image::fromBytes(self::png(120, 80))->encode(Format::Jpeg)->bytes);

        $fast = $jpeg->resize(width: 58, fast: true);
        self::assertSame([58, 38], [$fast->width(), $fast->height()]);
        self::assertSame($jpeg, $jpeg->resize(width: 120, fast: true));
    }

    public function testFastResizeLeavesTransparentEdgesClean(): void
    {
        $canvas = Fixtures::transparent(90, 90);
        imagefilledrectangle($canvas, 30, 30, 59, 59, 0xFFFFFF);
        $image = Image::fromBytes(Fixtures::encode($canvas, Format::Png))->resize(width: 26, fast: true);
        $pixels = imagecreatefromstring($image->encode(Format::Png)->bytes);
        self::assertInstanceOf(GdImage::class, $pixels);

        // Over white, a white square on transparency must stay white: GD's fast scaler would darken its edges.
        for ($y = 0; $y < 26; $y++) {
            for ($x = 0; $x < 26; $x++) {
                $pixel = imagecolorat($pixels, $x, $y);
                self::assertIsInt($pixel);
                $opacity = (127 - ($pixel >> 24)) / 127;
                self::assertGreaterThanOrEqual(254, ($pixel & 0xFF) * $opacity + 255 * (1 - $opacity));
            }
        }
    }

    public function testTransparencySurvivesOrientationResizingAndEncoding(): void
    {
        $png = Fixtures::encode(Fixtures::transparent(8, 4), Format::Png);
        $bytes = Fixtures::afterIhdr($png, Fixtures::pngChunk('eXIf', Fixtures::tiff(6)));

        foreach ([Format::Png, Format::Webp] as $format) {
            $encoded = Image::fromBytes($bytes)->resize(width: 2)->encode($format);
            self::assertSame([2, 4], [$encoded->width, $encoded->height]);
            $decoded = imagecreatefromstring($encoded->bytes);
            self::assertInstanceOf(GdImage::class, $decoded);
            $pixel = imagecolorat($decoded, 1, 3);
            self::assertIsInt($pixel);
            self::assertSame(127, ($pixel >> 24) & 0x7F, $format->name);
        }
    }

    public function testJpegCompositesTransparencyOnWhite(): void
    {
        $png = Fixtures::encode(Fixtures::transparent(8, 4), Format::Png);
        $jpeg = Image::fromBytes($png)->encode(Format::Jpeg, 100);

        $decoded = imagecreatefromstring($jpeg->bytes);
        self::assertInstanceOf(GdImage::class, $decoded);
        self::assertSame(0xFFFFFF, imagecolorat($decoded, 4, 2));
    }

    public function testJpegSourcesAreReencodedWithoutChangingTheirPixels(): void
    {
        $canvas = Fixtures::canvas(8, 8);
        imagefilledrectangle($canvas, 0, 0, 7, 7, 0x336699);
        $jpeg = Image::fromBytes(Fixtures::encode($canvas, Format::Jpeg))->resize(width: 4)->encode(Format::Jpeg, 100);

        $decoded = imagecreatefromstring($jpeg->bytes);
        self::assertInstanceOf(GdImage::class, $decoded);
        $pixel = imagecolorat($decoded, 2, 2);
        self::assertIsInt($pixel);
        self::assertEqualsWithDelta(0x33, ($pixel >> 16) & 0xFF, 3);
        self::assertEqualsWithDelta(0x66, ($pixel >> 8) & 0xFF, 3);
        self::assertEqualsWithDelta(0x99, $pixel & 0xFF, 3);
    }

    public function testRotatedJpegSourcesStayOpaque(): void
    {
        $canvas = Fixtures::canvas(16, 8);
        imagefilledrectangle($canvas, 0, 0, 15, 7, 0x336699);
        $jpeg = Fixtures::encode($canvas, Format::Jpeg);

        for ($orientation = 1; $orientation <= 8; $orientation++) {
            $bytes = substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', 34)
                . "Exif\0\0" . Fixtures::tiff($orientation) . substr($jpeg, 2);
            $decoded = imagecreatefromstring(Image::fromBytes($bytes)->encode(Format::Jpeg, 100)->bytes);
            self::assertInstanceOf(GdImage::class, $decoded);
            self::assertSame($orientation < 5 ? [16, 8] : [8, 16], [imagesx($decoded), imagesy($decoded)]);
            foreach ([[0, 0], [imagesx($decoded) - 1, imagesy($decoded) - 1]] as [$x, $y]) {
                $pixel = imagecolorat($decoded, $x, $y);
                self::assertIsInt($pixel);
                self::assertEqualsWithDelta(0x99, $pixel & 0xFF, 3, "orientation $orientation");
            }
        }
    }

    public function testPngHasAnAlphaChannelOnlyWhenTheSourceCanBeTransparent(): void
    {
        $jpeg = Image::fromBytes(Fixtures::encode(Fixtures::canvas(8, 4), Format::Jpeg));
        $png = Image::fromBytes(Fixtures::encode(Fixtures::transparent(8, 4), Format::Png));

        // Byte 25 of a PNG is its color type: 2 is RGB, 6 is RGBA.
        self::assertSame(2, ord($jpeg->encode(Format::Png)->bytes[25]));
        self::assertSame(2, ord($jpeg->resize(width: 4)->encode(Format::Png)->bytes[25]));
        self::assertSame(6, ord($png->encode(Format::Png)->bytes[25]));
    }

    public function testAnImageTooLargeForItsFormatFailsToEncode(): void
    {
        // WebP stores at most 16383 pixels per side.
        $image = Image::fromBytes(Fixtures::encode(Fixtures::canvas(16384, 1), Format::Png));

        $this->expectException(ImageException::class);
        $image->encode(Format::Webp);
    }

    /** @return iterable<string, array{?int, ?int}> */
    public static function invalidBounds(): iterable
    {
        yield 'missing' => [null, null];
        yield 'zero width' => [0, null];
        yield 'negative height' => [null, -1];
    }

    #[DataProvider('invalidBounds')]
    public function testInvalidResizeBoundsAreRejected(?int $width, ?int $height): void
    {
        $image = Image::fromBytes(self::png(10, 8));

        $this->expectException(\InvalidArgumentException::class);
        $image->resize($width, $height);
    }

    /** @return iterable<string, array{Format, int}> */
    public static function invalidQualities(): iterable
    {
        yield 'negative' => [Format::Jpeg, -1];
        yield 'above 100' => [Format::Webp, 101];
        yield 'png checks it too' => [Format::Png, 101];
    }

    #[DataProvider('invalidQualities')]
    public function testQualityOutsideTheRangeIsRejected(Format $format, int $quality): void
    {
        $image = Image::fromBytes(self::png(10, 8));

        $this->expectException(\InvalidArgumentException::class);
        $image->encode($format, $quality);
    }

    public function testByteLimitIsCheckedBeforeReadingImageMetadata(): void
    {
        $this->expectException(ImageTooLargeException::class);
        Image::fromBytes('not an image', new Limits(maxBytes: 5));
    }

    public function testPixelLimitIsCheckedBeforeDecodingRaster(): void
    {
        // A complete raster is unnecessary to reject dimensions from an oversized PNG header.
        $header = pack('NNCCCCC', 100000, 100000, 8, 2, 0, 0, 0);
        $bytes = "\x89PNG\r\n\x1a\n" . Fixtures::pngChunk('IHDR', $header) . Fixtures::pngChunk('IEND', '');

        $this->expectException(ImageTooLargeException::class);
        Image::fromBytes($bytes, new Limits(maxPixels: 100));
    }

    public function testExactInputLimitsAreAllowed(): void
    {
        $bytes = self::png(10, 8);
        $image = Image::fromBytes($bytes, new Limits(maxBytes: strlen($bytes), maxPixels: 80));

        self::assertSame(10, $image->width());
    }

    public function testPixelsMustDecodeAfterTheHeaderWasAccepted(): void
    {
        $broken = substr(self::png(10, 8), 0, 33) . Fixtures::pngChunk('IEND', '');

        $this->expectException(InvalidImageException::class);
        Image::fromBytes($broken);
    }

    public function testOptimizerReturnsNullWhenPngCannotBeMadeSmaller(): void
    {
        $original = Image::fromBytes(self::png(12, 8))->encode(Format::Png);

        self::assertNull((new Optimizer())->optimize($original->bytes, Format::Png));
    }

    public function testOptimizerOffersSmallerBytesWithTheRequestedBounds(): void
    {
        $original = self::png(120, 80);
        // Extra metadata or trailing data is discarded by encoding.
        $original .= str_repeat('metadata', 1000);
        $result = (new Optimizer())->optimize($original, Format::Webp, maxWidth: 30);

        self::assertNotNull($result);
        self::assertSame([30, 20], [$result->width, $result->height]);
        self::assertLessThan(strlen($original), $result->size());
        self::assertSame('image/webp', $result->mimeType());
    }

    public function testOptimizerCanResizeFast(): void
    {
        $jpeg = Image::fromBytes(self::png(120, 80))->encode(Format::Jpeg, 100)->bytes;
        $result = (new Optimizer())->optimize($jpeg, Format::Jpeg, maxWidth: 30, fast: true);

        self::assertNotNull($result);
        self::assertSame([30, 20], [$result->width, $result->height]);
    }

    public function testOptimizerPropagatesLimits(): void
    {
        $this->expectException(ImageTooLargeException::class);
        (new Optimizer(new Limits(maxPixels: 1)))->optimize(self::png(2, 2));
    }

    public function testOptimizerPropagatesInvalidInput(): void
    {
        $this->expectException(InvalidImageException::class);
        (new Optimizer())->optimize('not an image');
    }

    public function testAnimatedInputIsRejectedThroughThePublicApi(): void
    {
        $bytes = Fixtures::afterIhdr(self::png(10, 8), Fixtures::pngChunk('acTL', pack('NN', 2, 0)));

        $this->expectException(AnimatedImageException::class);
        (new Optimizer())->optimize($bytes);
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidLimits(): iterable
    {
        yield 'zero bytes' => [0, 1];
        yield 'negative pixels' => [1, -1];
    }

    #[DataProvider('invalidLimits')]
    public function testLimitsMustBePositive(int $maxBytes, int $maxPixels): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Limits($maxBytes, $maxPixels);
    }

    /** @return array<int|string, mixed> */
    private static function dimensions(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        self::assertIsArray($info);
        return $info;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function png(int $width, int $height): string
    {
        $image = Fixtures::canvas($width, $height);
        for ($y = 0; $y < $height; $y++) {
            imagefilledrectangle($image, 0, $y, $width - 1, $y, ($y % 256) << 16 | 0x0080FF);
        }

        return Fixtures::encode($image, Format::Png);
    }
}
