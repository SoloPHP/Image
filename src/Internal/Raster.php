<?php

declare(strict_types=1);

namespace Solo\Image\Internal;

use GdImage;
use Solo\Image\Exception\ImageException;

/**
 * Turns decoded pixels into truecolor rasters with an alpha channel, then orients, resizes and places them.
 *
 * normalize() and mirror() take ownership of a freshly decoded image and may change it in place.
 * resize(), turn() and place() never change their source.
 *
 * @internal
 */
final class Raster
{
    /** Makes every kind of GD image truecolor with real alpha, copying only when the pixels must change. */
    public static function normalize(GdImage $image): GdImage
    {
        // Palette images and color-key transparency become alpha when copied onto a transparent canvas:
        // imagecopy() skips color-key pixels, so they stay as the canvas was.
        if (!imageistruecolor($image) || imagecolortransparent($image) >= 0) {
            return self::place($image, imagesx($image), imagesy($image), 0, 0);
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
     * The size that fits inside the bounds with the aspect ratio kept. The side that constrains takes its bound
     * exactly and the other is rounded down, to at least one pixel. Without $upscale, the source size is a bound too.
     *
     * @return array{positive-int, positive-int}
     */
    public static function fit(int $sourceWidth, int $sourceHeight, ?int $width, ?int $height, bool $upscale): array
    {
        if ($width === null && $height === null) {
            throw new \InvalidArgumentException('At least one resize bound is required');
        }
        if (($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new \InvalidArgumentException('Resize bounds must be positive');
        }

        $targetWidth = $width ?? $sourceWidth;
        $targetHeight = $height ?? $sourceHeight;
        if (!$upscale) {
            $targetWidth = min($targetWidth, $sourceWidth);
            $targetHeight = min($targetHeight, $sourceHeight);
        }
        if ($targetWidth * $sourceHeight <= $targetHeight * $sourceWidth) {
            $targetHeight = max(1, intdiv($sourceHeight * $targetWidth, $sourceWidth));
        } else {
            $targetWidth = max(1, intdiv($sourceWidth * $targetHeight, $sourceHeight));
        }

        return [max(1, $targetWidth), max(1, $targetHeight)];
    }

    /**
     * Returns the source itself when it already has the size: images are never modified, so sharing is safe.
     * imagecopyresampled() averages every source pixel, weighted by its alpha. $fast, meant for reducing opaque
     * pixels, uses GD's bilinear scaler instead: it reads four source pixels per result pixel, so it skips most
     * of a large reduction, and it lets transparent pixels darken the edges next to them.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function resize(GdImage $source, int $width, int $height, bool $fast = false): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($width === $sourceWidth && $height === $sourceHeight) {
            return $source;
        }

        if ($fast) {
            $image = @imagescale($source, $width, $height, IMG_BILINEAR_FIXED);
            if ($image === false || imagesx($image) !== $width || imagesy($image) !== $height) {
                throw new ImageException('Could not resize the image');
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);

            return $image;
        }

        $image = self::canvas($width, $height);
        if (!imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
            throw new ImageException('Could not resize the image');
        }

        return $image;
    }

    /**
     * Puts an image on a transparent canvas with its top left corner at $x, $y. A rectangle, unlike imagefill()'s
     * flood fill, needs no working memory proportional to the image.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    public static function place(GdImage $image, int $width, int $height, int $x, int $y): GdImage
    {
        $canvas = self::canvas($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, 0x7F000000);
        if (!imagecopy($canvas, $image, $x, $y, 0, 0, imagesx($image), imagesy($image))) {
            throw new ImageException('Could not copy the image');
        }

        return $canvas;
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
