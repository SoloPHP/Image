<?php

declare(strict_types=1);

namespace Solo\Image\Internal;

/**
 * Reads the orientation of JPEG bytes or a TIFF metadata block through ext-exif.
 *
 * @internal
 */
final class ExifOrientation
{
    public static function read(string $bytes): int
    {
        if (str_starts_with($bytes, "Exif\0\0")) {
            $bytes = substr($bytes, 6);
        }
        if ($bytes === '') {
            return 1;
        }

        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            return 1;
        }

        fwrite($stream, $bytes);
        rewind($stream);

        $metadata = @exif_read_data($stream, 'IFD0', true);
        $ifd = $metadata['IFD0'] ?? null;
        $orientation = is_array($ifd) ? ($ifd['Orientation'] ?? null) : null;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }
}
