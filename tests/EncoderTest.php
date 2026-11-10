<?php

declare(strict_types=1);

namespace Solo\Image\Tests;

use GdImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Image\Format;
use Solo\Image\Internal\Encoder;

final class EncoderTest extends TestCase
{
    /** @return iterable<string, array{Format, int}> */
    public static function formats(): iterable
    {
        yield 'jpeg' => [Format::Jpeg, IMAGETYPE_JPEG];
        yield 'png' => [Format::Png, IMAGETYPE_PNG];
        yield 'webp' => [Format::Webp, IMAGETYPE_WEBP];
    }

    #[DataProvider('formats')]
    public function testEncodedBytesHaveTheRequestedFormatAndDimensions(Format $format, int $type): void
    {
        $image = Fixtures::transparent(4, 2);
        $bytes = Encoder::encode($image, $format, 82, false);
        $info = getimagesizefromstring($bytes);
        self::assertIsArray($info);
        self::assertSame([4, 2, $type], array_slice($info, 0, 3));
        self::assertInstanceOf(GdImage::class, imagecreatefromstring($bytes));
    }

    public function testPngAndWebpPreserveAlpha(): void
    {
        foreach ([Format::Png, Format::Webp] as $format) {
            $image = imagecreatefromstring(Encoder::encode(Fixtures::transparent(4, 2), $format, 82, false));
            self::assertInstanceOf(GdImage::class, $image);
            $pixel = imagecolorat($image, 0, 0);
            self::assertIsInt($pixel);
            self::assertSame(127, ($pixel >> 24) & 0x7F);
        }
    }

    public function testJpegFlattensTransparencyOnWhiteWithoutChangingTheSource(): void
    {
        $source = Fixtures::transparent(4, 2);
        $image = imagecreatefromstring(Encoder::encode($source, Format::Jpeg, 100, false));
        self::assertInstanceOf(GdImage::class, $image);
        self::assertSame(0xFFFFFF, imagecolorat($image, 0, 0));
        self::assertSame(0x7F000000, imagecolorat($source, 0, 0));
    }

    public function testEncodingLeavesTheOutputBufferAlone(): void
    {
        ob_start();
        try {
            echo 'before';
            $bytes = Encoder::encode(Fixtures::transparent(4, 2), Format::Png, 82, false);
            self::assertSame('before', ob_get_contents());
            self::assertNotEmpty($bytes);
        } finally {
            ob_end_clean();
        }
    }
}
