<?php

declare(strict_types=1);

namespace Solo\Image\Exception;

/** Encoded bytes or source pixels exceed the configured limits. */
class ImageTooLargeException extends ImageException
{
}
