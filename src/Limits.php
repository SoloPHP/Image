<?php

declare(strict_types=1);

namespace Solo\Image;

/** Input limits checked before GD allocates a raster. */
final readonly class Limits
{
    public function __construct(
        public int $maxBytes = 20_971_520,
        public int $maxPixels = 20_000_000,
    ) {
        if ($maxBytes < 1 || $maxPixels < 1) {
            throw new \InvalidArgumentException('Image byte and pixel limits must be positive');
        }
    }
}
