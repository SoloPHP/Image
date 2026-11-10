# Solo Image

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/image.svg)](https://packagist.org/packages/solophp/image)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A small, framework-independent PHP library for processing still images with GD.

- JPEG, PNG and WebP input and output.
- Automatic EXIF orientation, including mirrored orientations and EXIF in PNG/WebP.
- Immutable images: decode once, create multiple variants independently.
- Resize within explicit bounds while preserving aspect ratio, without upscaling, with an optional fast mode
  for opaque images.
- Fit a picture into an exact canvas with transparent margins, for icons and fixed slots.
- Reject animated PNG and WebP, or take their first frame on request.
- Preserve transparency in PNG/WebP; composite it on white for JPEG.
- Optional optimization that returns a result only when it is smaller than the input.
- Configurable byte and pixel limits checked before raster decoding.

The API accepts and returns bytes. Your application handles files, uploads, HTTP responses,
object storage, naming and cache keys. There are no framework or storage dependencies.

## Requirements

- PHP 8.3 or newer.
- `ext-gd`, built with support for the formats you use (JPEG, PNG, WebP).
- `ext-exif`.

GIF, SVG, AVIF and HEIC are outside the supported formats. Animated PNG and WebP are
rejected unless you ask for their first frame with `Image::fromBytes($bytes, firstFrame: true)`.

## Installation

Version 1.0 is a release candidate, so Composer needs to be told that a pre-release is acceptable:

```bash
composer require "solophp/image:^1.0@rc"
```

## Quick start

```php
use Solo\Image\Format;
use Solo\Image\Image;

// $bytes holds the original image, read by your application.
$image = Image::fromBytes($bytes);

$thumbnail = $image->resize(width: 320)->encode(Format::Webp);
$preview = $image->resize(width: 1200, height: 900)->encode(Format::Jpeg, quality: 85);

file_put_contents("/path/to/thumb.{$thumbnail->extension()}", $thumbnail->bytes);
file_put_contents("/path/to/preview.{$preview->extension()}", $preview->bytes);
```

`fromBytes()` detects the format from the contents, checks the limits and applies the EXIF
orientation. `resize()` keeps the aspect ratio and never enlarges. Each result carries its
bytes, format, MIME type, extension and dimensions, and `$image` stays as it was decoded.

For an icon of an exact size, `contain()` fits the whole picture and centres it on transparent margins:

```php
$icon = Image::fromBytes($bytes, firstFrame: true)->contain(512, 512)->encode(Format::Png);
```

To keep a re-encoded image only when it is smaller than the original:

```php
use Solo\Image\Optimizer;

$result = (new Optimizer())->optimize($bytes, Format::Webp, maxWidth: 1600, maxHeight: 1600);
// An EncodedImage, or null when the original bytes are the better choice.
```

## Documentation

**[Full Documentation](https://solophp.github.io/Image/)**

- [Getting Started](https://solophp.github.io/Image/guide/)
- [Resizing](https://solophp.github.io/Image/guide/#resize)
- [Exact Canvas](https://solophp.github.io/Image/guide/#fit-an-exact-canvas)
- [Animated Input](https://solophp.github.io/Image/guide/#animated-input)
- [Encoding](https://solophp.github.io/Image/guide/#encode)
- [Several Variants from One Source](https://solophp.github.io/Image/guide/#make-several-variants-from-one-source)
- [Smaller-Only Optimization](https://solophp.github.io/Image/guide/#keep-only-smaller-results)
- [Input Limits](https://solophp.github.io/Image/guide/#limit-the-input)
- [Errors](https://solophp.github.io/Image/guide/#handle-errors)
- [API Reference](https://solophp.github.io/Image/guide/api)
- [Behavior in Detail](https://solophp.github.io/Image/guide/details): orientation, transparency, rounding, memory and damaged files
- [Changelog](CHANGELOG.md)

## Development

```bash
composer install
composer check
```

The checks include Composer validation, PSR-12, PHPStan at its maximum level and PHPUnit.
Tests create small images in memory and cover orientation, transparency, resizing,
format conversion, exact canvases, input limits, malformed containers, animation
detection and first frames of animated PNG and WebP.
CI runs on PHP 8.3, 8.4 and 8.5, including the lowest supported development dependencies.

## License

MIT. See [LICENSE](LICENSE).
