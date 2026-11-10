<?php

declare(strict_types=1);

namespace Solo\Image\Internal;

use GdImage;
use Solo\Image\Exception\ImageException;

/**
 * Turns decoded pixels into truecolor rasters with an alpha channel, then orients and resizes them.
 *
 * normalize() and mirror() take ownership of a freshly decoded image and may change it in place.
 * resize() and turn() never change their source.
 *
 * @internal
 */
final class Raster
{
    /** Makes every kind of GD image truecolor with real alpha, copying only when the pixels must change. */
    public static function normalize(GdImage $image): GdImage
    {
        // Palette images and color-key transparency become alpha when copied onto a transparent canvas.
        if (!imageistruecolor($image) || imagecolortransparent($image) >= 0) {
            return self::copy($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Mirrors a normalized image in place as its EXIF orientation, 1 to 8, asks, and returns the quarter
     * turn that is still due: 0, 90 (counterclockwise) or -90 degrees. Every orientation is a mirror
     * followed by a turn, and only the turn needs a second raster, so it is left for turn() to do later.
     */
    public static function mirror(GdImage $image, int $orientation): int
    {
        $flip = match ($orientation) {
            2, 7 => IMG_FLIP_HORIZONTAL,
            3 => IMG_FLIP_BOTH,
            4, 5 => IMG_FLIP_VERTICAL,
            default => null,
        };
        if ($flip !== null) {
            imageflip($image, $flip);
        }

        return match ($orientation) {
            5, 6, 7 => -90,
            8 => 90,
            default => 0,
        };
    }

    /** Returns a new raster turned by 90 or -90 degrees, or the image itself for 0. The source is unchanged. */
    public static function turn(GdImage $image, int $angle): GdImage
    {
        if ($angle === 0) {
            return $image;
        }

        $turned = @imagerotate($image, $angle, 0x7F000000);
        if ($turned === false) {
            throw new ImageException('Could not rotate the image');
        }
        imagealphablending($turned, false);
        imagesavealpha($turned, true);

        return $turned;
    }

    /**
     * Fits inside the supplied bounds, keeping the aspect ratio and original size as upper limits.
     * Returns the source itself when it already fits: images are never modified, so sharing is safe.
     */
    public static function resize(GdImage $source, ?int $width, ?int $height): GdImage
    {
        if ($width === null && $height === null) {
            throw new \InvalidArgumentException('At least one resize bound is required');
        }
        if (($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new \InvalidArgumentException('Resize bounds must be positive');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = min($width ?? $sourceWidth, $sourceWidth);
        $targetHeight = min($height ?? $sourceHeight, $sourceHeight);
        if ($targetWidth * $sourceHeight <= $targetHeight * $sourceWidth) {
            $targetHeight = max(1, intdiv($sourceHeight * $targetWidth, $sourceWidth));
        } else {
            $targetWidth = max(1, intdiv($sourceWidth * $targetHeight, $sourceHeight));
        }
        if ($targetWidth === $sourceWidth && $targetHeight === $sourceHeight) {
            return $source;
        }

        $image = self::canvas($targetWidth, $targetHeight);
        $copied = imagecopyresampled(
            $image,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );
        if (!$copied) {
            throw new ImageException('Could not resize the image');
        }

        return $image;
    }

    private static function copy(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $image = self::canvas($width, $height);
        // imagecopy() skips color-key pixels, so they must start out transparent. A rectangle, unlike
        // imagefill()'s flood fill, needs no working memory proportional to the image.
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, 0x7F000000);
        if (!imagecopy($image, $source, 0, 0, 0, 0, $width, $height)) {
            throw new ImageException('Could not copy the image');
        }

        return $image;
    }

    /**
     * A truecolor image that stores alpha. Its pixels are for the caller to write.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function canvas(int $width, int $height): GdImage
    {
        $image = @imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new ImageException('Could not allocate an image');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }
}
