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
    /**
     * @param positive-int $width
     * @param positive-int $height
     * @param array{bytes: string, x: int, y: int, width: int, height: int}|null $frame The first frame of an
     *        animated WebP as a still WebP of its own, and where it lies on the canvas.
     */
    private function __construct(
        public int $width,
        public int $height,
        public Format $format,
        public int $orientation,
        public ?array $frame = null,
    ) {
    }

    /** With $firstFrame, an animation is accepted instead of rejected and its first frame is located. */
    public static function inspect(string $bytes, bool $firstFrame = false): self
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidImageException('Cannot read image dimensions');
        }

        $format = Format::tryFrom($info['mime'])
            ?? throw new UnsupportedFormatException('Only JPEG, PNG and static WebP are supported');
        if ($format === Format::Webp) {
            [$orientation, $frame] = self::scanWebp($bytes, $firstFrame);
            if (
                $frame !== null
                && ($frame['x'] + $frame['width'] > $info[0] || $frame['y'] + $frame['height'] > $info[1])
            ) {
                throw new InvalidImageException('WebP frame lies outside the canvas');
            }

            return new self($info[0], $info[1], $format, $orientation, $frame);
        }

        $orientation = $format === Format::Png ? self::scanPng($bytes, $firstFrame) : ExifOrientation::read($bytes);

        return new self($info[0], $info[1], $format, $orientation);
    }

    /**
     * Walks the RIFF chunks: rejects truncated files and, unless $firstFrame, animated ones.
     *
     * @return array{int, array{bytes: string, x: int, y: int, width: int, height: int}|null}
     *         The EXIF orientation and the first frame of an animation.
     */
    private static function scanWebp(string $bytes, bool $firstFrame): array
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
        $animated = false;
        $frame = null;
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
                $firstFrame || throw new AnimatedImageException('Animated WebP cannot be processed as a static image');
                $animated = true;
            }
            if ($type === 'ANMF' && $frame === null) {
                $frame = self::webpFrame(substr($bytes, $payload, $size));
            }
            if ($type === 'EXIF') {
                $exif = substr($bytes, $payload, $size);
            }
            // Chunks are padded to even sizes. Some writers omit the padding after the last chunk.
            $offset = $payload + $size + ($size % 2);
        }
        if ($animated && $frame === null) {
            throw new InvalidImageException('Animated WebP has no frames');
        }

        return [ExifOrientation::read($exif), $frame];
    }

    /**
     * Turns the payload of an ANMF chunk into a still WebP: its bitstream, with the alpha chunk and a VP8X
     * header when a lossy frame has alpha. Unknown chunks in the frame are left out.
     *
     * @return array{bytes: string, x: int, y: int, width: int, height: int}
     */
    private static function webpFrame(string $payload): array
    {
        $length = strlen($payload);
        if ($length < 16) {
            throw new InvalidImageException('Truncated WebP frame');
        }
        $width = self::uint24($payload, 6) + 1;
        $height = self::uint24($payload, 9) + 1;

        $alpha = null;
        $offset = 16;
        while ($offset < $length) {
            if ($length - $offset < 8) {
                throw new InvalidImageException('Truncated WebP frame');
            }
            $type = substr($payload, $offset, 4);
            $size = self::uint32('V', $payload, $offset + 4);
            if ($size < 0 || $size > $length - $offset - 8) {
                throw new InvalidImageException('Truncated WebP frame');
            }
            $data = substr($payload, $offset + 8, $size);
            if ($type === 'ALPH') {
                $alpha ??= $data;
            }
            if ($type === 'VP8 ' || $type === 'VP8L') {
                // The bitstream's own size is what the decoder allocates, so it must be the size the limits saw.
                $bitstream = self::riffChunk($type, $data);
                $info = @getimagesizefromstring(self::riff($bitstream));
                if ($info === false || $info[0] !== $width || $info[1] !== $height) {
                    throw new InvalidImageException('WebP frame size does not match its header');
                }
                $header = $type === 'VP8 ' && $alpha !== null
                    ? self::riffChunk('VP8X', "\x10\0\0\0" . self::pack24($width - 1) . self::pack24($height - 1))
                        . self::riffChunk('ALPH', $alpha)
                    : '';

                return [
                    'bytes' => self::riff($header . $bitstream),
                    'x' => self::uint24($payload, 0) * 2,
                    'y' => self::uint24($payload, 3) * 2,
                    'width' => $width,
                    'height' => $height,
                ];
            }
            $offset += 8 + $size + ($size % 2);
        }

        throw new InvalidImageException('WebP frame has no image data');
    }

    private static function riff(string $chunks): string
    {
        return 'RIFF' . pack('V', strlen($chunks) + 4) . 'WEBP' . $chunks;
    }

    private static function riffChunk(string $type, string $data): string
    {
        return $type . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 1 ? "\0" : '');
    }

    private static function uint24(string $bytes, int $offset): int
    {
        return self::uint32('V', substr($bytes, $offset, 3) . "\0", 0);
    }

    private static function pack24(int $value): string
    {
        return substr(pack('V', $value), 0, 3);
    }

    /**
     * Walks the PNG chunks: rejects truncated files and, unless $firstFrame, animated ones.
     * Returns the EXIF orientation. GD reads the default image of an animated PNG and skips the frames.
     */
    private static function scanPng(string $bytes, bool $firstFrame): int
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
            if (!$firstFrame && ($type === 'acTL' || $type === 'fcTL' || $type === 'fdAT')) {
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
