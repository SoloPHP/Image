<?php

declare(strict_types=1);

namespace Solo\Image;

/** Offers a re-encoded image only when its bytes are smaller than the original. */
final readonly class Optimizer
{
    public function __construct(private Limits $limits = new Limits())
    {
    }

    /**
     * Null means keep the original bytes. Invalid, oversized, unsupported and animated inputs raise exceptions.
     * Bounds apply to the candidate. Use Image::resize()->encode() when dimensions or output format are mandatory.
     * $fast resizes as Image::resize() does with it.
     */
    public function optimize(
        string $bytes,
        Format $format = Format::Webp,
        int $quality = 82,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        bool $fast = false,
    ): ?EncodedImage {
        $image = Image::fromBytes($bytes, $this->limits);
        if ($maxWidth !== null || $maxHeight !== null) {
            $image = $image->resize($maxWidth, $maxHeight, $fast);
        }
        $encoded = $image->encode($format, $quality);

        return $encoded->size() < strlen($bytes) ? $encoded : null;
    }
}
