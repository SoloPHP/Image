<?php

declare(strict_types=1);

namespace Solo\Image\Tests;

use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Image\Internal\Raster;

final class RasterTest extends TestCase
{
    /** @return iterable<string, array{int, list<list<int>>}> */
    public static function orientations(): iterable
    {
        yield 'normal' => [1, [[1, 2, 3], [4, 5, 6]]];
        yield 'horizontal mirror' => [2, [[3, 2, 1], [6, 5, 4]]];
        yield 'half turn' => [3, [[6, 5, 4], [3, 2, 1]]];
        yield 'vertical mirror' => [4, [[4, 5, 6], [1, 2, 3]]];
        yield 'transpose' => [5, [[1, 4], [2, 5], [3, 6]]];
        yield 'clockwise' => [6, [[4, 1], [5, 2], [6, 3]]];
        yield 'transverse' => [7, [[6, 3], [5, 2], [4, 1]]];
        yield 'counterclockwise' => [8, [[3, 6], [2, 5], [1, 4]]];
    }

    /** @param list<list<int>> $expected */
    #[DataProvider('orientations')]
    public function testOrientationTransformsPixels(int $orientation, array $expected): void
    {
        $source = Fixtures::canvas(3, 2);
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 3; $x++) {
                imagesetpixel($source, $x, $y, ($y * 3 + $x + 1) << 16);
            }
        }

        $image = self::orient($source, $orientation);
        self::assertSame(count($expected[0]), imagesx($image));
        self::assertSame(count($expected), imagesy($image));
        foreach ($expected as $y => $row) {
            foreach ($row as $x => $value) {
                self::assertSame($value << 16, imagecolorat($image, $x, $y));
            }
        }
    }

    public function testEveryOrientationKeepsTransparency(): void
    {
        for ($orientation = 1; $orientation <= 8; $orientation++) {
            $image = self::orient(Fixtures::transparent(3, 2), $orientation);
            self::assertSame(0x7F000000, imagecolorat($image, 0, 0), "orientation $orientation");
            self::assertSame(0x7F000000, imagecolorat($image, imagesx($image) - 1, imagesy($image) - 1));
        }
    }

    public function testTruecolorImagesAreNormalizedWithoutCopying(): void
    {
        $source = Fixtures::canvas(3, 2);

        self::assertSame($source, Raster::normalize($source));
        self::assertSame(0, Raster::mirror($source, 1));
        self::assertSame($source, Raster::turn($source, 0));
    }

    public function testMirroringHappensInPlaceAndTurningDoesNot(): void
    {
        $source = Fixtures::canvas(3, 2);
        imagesetpixel($source, 0, 0, 0xFF0000);

        self::assertSame(-90, Raster::mirror($source, 7));
        self::assertSame(0xFF0000, imagecolorat($source, 2, 0));

        $turned = Raster::turn($source, -90);
        self::assertNotSame($source, $turned);
        self::assertSame([2, 3], [imagesx($turned), imagesy($turned)]);
        self::assertSame([3, 2], [imagesx($source), imagesy($source)]);
        self::assertSame(0xFF0000, imagecolorat($source, 2, 0));
    }

    public function testBothBoundsAreRespectedWithoutUpscaling(): void
    {
        self::assertSame([60, 40], Raster::fit(120, 80, 100, 40, false));
        self::assertSame([120, 80], Raster::fit(120, 80, 500, 500, false));
        self::assertSame([120, 80], Raster::fit(120, 80, 120, null, false));

        $source = Fixtures::canvas(120, 80);
        $image = Raster::resize($source, 60, 40);
        self::assertSame([60, 40], [imagesx($image), imagesy($image)]);
        self::assertSame([120, 80], [imagesx($source), imagesy($source)]);
        self::assertSame($source, Raster::resize($source, 120, 80));
    }

    public function testUpscalingFillsTheConstrainingBound(): void
    {
        self::assertSame([300, 200], Raster::fit(120, 80, 300, 300, true));
        self::assertSame([20, 30], Raster::fit(80, 120, 30, 30, true));
        self::assertSame([100, 33], Raster::fit(3, 1, 100, 100, true));
    }

    public function testTheConstrainingWidthIsExactDespiteFloatingPointRounding(): void
    {
        self::assertSame([58, 29], Raster::fit(100, 50, 58, null, false));
    }

    public function testAHeightBoundAloneFitsPortraitImages(): void
    {
        self::assertSame([40, 60], Raster::fit(80, 120, null, 60, false));
    }

    public function testVeryThinImagesKeepAtLeastOnePixel(): void
    {
        self::assertSame([1, 1], Raster::fit(100, 1, 1, null, false));
    }

    public function testFastResizeKeepsOpaqueEdges(): void
    {
        $source = Fixtures::canvas(403, 302);
        imagefilledrectangle($source, 0, 0, 402, 301, 0xFFFFFF);

        // GD's bilinear scaler works in fixed point and can be off by two levels anywhere, edges included.
        $image = Raster::resize($source, 160, 119, true);
        self::assertSame([160, 119], [imagesx($image), imagesy($image)]);
        foreach ([[0, 0], [159, 0], [0, 118], [159, 118], [80, 60]] as [$x, $y]) {
            $pixel = imagecolorat($image, $x, $y);
            self::assertIsInt($pixel);
            self::assertSame(0, $pixel >> 24);
            self::assertEqualsWithDelta(255, $pixel & 0xFF, 2);
            self::assertEqualsWithDelta(255, ($pixel >> 16) & 0xFF, 2);
        }
    }

    public function testPlacingLeavesTheRestTransparent(): void
    {
        $source = Fixtures::canvas(2, 2);
        imagefilledrectangle($source, 0, 0, 1, 1, 0x336699);

        $image = Raster::place($source, 5, 4, 3, 1);
        self::assertSame([5, 4], [imagesx($image), imagesy($image)]);
        self::assertSame(0x336699, imagecolorat($image, 3, 1));
        self::assertSame(0x336699, imagecolorat($image, 4, 2));
        self::assertSame(0x7F000000, imagecolorat($image, 2, 1));
        self::assertSame(0x7F000000, imagecolorat($image, 4, 3));
    }

    public function testIndexedTransparencyBecomesAlphaAndSurvivesOrientationAndResize(): void
    {
        $source = imagecreate(4, 4);
        self::assertInstanceOf(GdImage::class, $source);
        $transparent = imagecolorallocate($source, 10, 20, 30);
        self::assertIsInt($transparent);
        imagefill($source, 0, 0, $transparent);
        imagecolortransparent($source, $transparent);

        $normalized = Raster::normalize($source);
        self::assertNotSame($source, $normalized);
        foreach ([$normalized, Raster::turn($normalized, -90), Raster::resize($normalized, 2, 2)] as $image) {
            self::assertTrue(imageistruecolor($image));
            self::assertSame(0x7F000000, imagecolorat($image, 0, 0));
        }
    }

    public function testTruecolorColorKeyBecomesAlpha(): void
    {
        $source = Fixtures::canvas(2, 1);
        imagesetpixel($source, 0, 0, 0xFF0000);
        imagesetpixel($source, 1, 0, 0x0000FF);
        imagecolortransparent($source, 0xFF0000);

        $image = Raster::normalize($source);
        self::assertSame(0x7F000000, imagecolorat($image, 0, 0));
        self::assertSame(0x0000FF, imagecolorat($image, 1, 0));
    }

    /** Decodes as Decoder does, then finishes the turn at once. */
    private static function orient(GdImage $image, int $orientation): GdImage
    {
        $image = Raster::normalize($image);

        return Raster::turn($image, Raster::mirror($image, $orientation));
    }
}
