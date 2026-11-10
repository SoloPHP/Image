<?php

declare(strict_types=1);

namespace Solo\Image\Internal;

use Solo\Image\Exception\AnimatedImageException;
use Solo\Image\Exception\InvalidImageException;
use Solo\Image\Exception\UnsupportedFormatException;
use Solo\Image\Format;

/**
 * Inspects encoded dimensions, format, animation and orientation before raster decoding.
 *
 * @internal
 */
final readonly class Metadata
{
    private function __construct(
        public int $width,
        public int $height,
        public Format $format,
        public int $orientation,
    ) {
    }

    public static function inspect(string $bytes): self
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidImageException('Cannot read image dimensions');
        }

        $format = Format::tryFrom($info['mime'])
            ?? throw new UnsupportedFormatException('Only JPEG, PNG and static WebP are supported');
        $orientation = match ($format) {
            Format::Jpeg => ExifOrientation::read($bytes),
            Format::Png => self::scanPng($bytes),
            Format::Webp => self::scanWebp($bytes),
        };

        return new self($info[0], $info[1], $format, $orientation);
    }

    /** Walks the RIFF chunks: rejects truncated and animated files and returns the EXIF orientation. */
    private static function scanWebp(string $bytes): int
    {
        $length = strlen($bytes);
        if ($length < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            throw new InvalidImageException('Invalid WebP container');
        }

        $size = self::uint32('V', $bytes, 4);
        if ($size < 4 || $size > $length - 8) {
            throw new InvalidImageException('Truncated WebP container');
        }

        $end = $size + 8;
        $offset = 12;
        $exif = '';
        while ($offset < $end) {
            if ($end - $offset < 8) {
                throw new InvalidImageException('Truncated WebP chunk');
            }
            $type = substr($bytes, $offset, 4);
            $size = self::uint32('V', $bytes, $offset + 4);
            $payload = $offset + 8;
            if ($size < 0 || $size > $end - $payload) {
                throw new InvalidImageException('Truncated WebP chunk');
            }

            if (
                $type === 'ANIM'
                || $type === 'ANMF'
                || ($type === 'VP8X' && $size > 0 && (ord($bytes[$payload]) & 0x02) !== 0)
            ) {
                throw new AnimatedImageException('Animated WebP cannot be processed as a static image');
            }
            if ($type === 'EXIF') {
                $exif = substr($bytes, $payload, $size);
            }
            // Chunks are padded to even sizes. Some writers omit the padding after the last chunk.
            $offset = $payload + $size + ($size % 2);
        }

        return ExifOrientation::read($exif);
    }

    /** Walks the PNG chunks: rejects truncated and animated files and returns the EXIF orientation. */
    private static function scanPng(string $bytes): int
    {
        $length = strlen($bytes);
        $offset = 8;
        $exif = '';
        while ($offset < $length) {
            if ($length - $offset < 12) {
                throw new InvalidImageException('Truncated PNG chunk');
            }
            $size = self::uint32('N', $bytes, $offset);
            if ($size < 0 || $size > $length - $offset - 12) {
                throw new InvalidImageException('Truncated PNG chunk');
            }
            $type = substr($bytes, $offset + 4, 4);
            if ($type === 'acTL' || $type === 'fcTL' || $type === 'fdAT') {
                throw new AnimatedImageException('Animated PNG cannot be processed as a static image');
            }
            if ($type === 'eXIf') {
                $exif = substr($bytes, $offset + 8, $size);
            }
            if ($type === 'IEND') {
                return ExifOrientation::read($exif);
            }
            $offset += $size + 12;
        }

        throw new InvalidImageException('PNG has no end chunk');
    }

    /** Reads four bytes the caller knows are there: "V" is little-endian, "N" big-endian. */
    private static function uint32(string $order, string $bytes, int $offset): int
    {
        $value = unpack($order, $bytes, $offset);

        return is_array($value) && is_int($value[1]) ? $value[1] : -1;
    }
}
