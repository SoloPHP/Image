# Getting started

Solo Image decodes a still image from bytes, turns it the right way up, resizes
it within bounds and encodes it as JPEG, PNG or WebP. It works on strings:
your application reads the upload and stores the result. `Image` is the decoded
picture, `EncodedImage` is what comes out, and `Optimizer` re-encodes an image
and keeps the result only when it is smaller.

## Install

PHP 8.3+ is required, with the `gd` and `exif` extensions. Version 1.0 is a release candidate, so Composer needs to be told that a pre-release is acceptable:

```sh
composer require "solophp/image:^1.0@rc"
```

GD must be built with the formats you use. Check with `gd_info()`: it reports
`JPEG Support`, `PNG Support` and `WebP Support`. A missing codec raises
`UnsupportedFormatException` when an image needs it.

## Decode an image

```php
use Solo\Image\Image;

$bytes = file_get_contents($_FILES['photo']['tmp_name']);
$image = Image::fromBytes($bytes);

$image->width();   // 4000
$image->height();  // 3000
```

`fromBytes()` detects JPEG, PNG or WebP from the contents. A file name or the
MIME type an upload declares is never trusted. EXIF orientation is applied, so
`width()` and `height()` describe the picture as people see it: a phone photo
taken upright is taller than wide.

GIF, AVIF, HEIC and SVG are not supported. Animated PNG and WebP are rejected
with `AnimatedImageException` unless you [ask for their first frame](#animated-input).

## Resize

```php
$image->resize(width: 1200);               // at most 1200 wide
$image->resize(height: 900);               // at most 900 high
$image->resize(width: 1200, height: 900);  // fits inside 1200 × 900
```

The aspect ratio is kept and an image is never enlarged. With one bound the
other side follows from the aspect ratio. With two, the image fits inside the
box, so a 4000 × 3000 photo resized to `width: 1200, height: 600` becomes
800 × 600.

`resize()` returns a new image and leaves the one it was called on unchanged.
When the image already fits, the same instance comes back and no pixels are
copied.

### Faster, at a cost

```php
$image->resize(width: 1600, fast: true);
```

By default every source pixel counts towards the result. `fast: true` reads
four source pixels per result pixel instead, which makes turning a 12-megapixel
JPEG into a 1600-pixel WebP a quarter to two fifths faster. The price is
aliasing: fine detail and noise come out grainier, and the encoded file is
larger. Use it to cap the size of an upload, where the reduction is small, and
leave it off for thumbnails. It applies to images decoded from JPEG. For PNG
and WebP sources, which can be transparent, it is ignored, because GD's fast
scaler darkens the edges of transparent areas.

## Fit an exact canvas

```php
$icon = $image->contain(512, 512);                    // exactly 512 × 512
$slot = $image->contain(400, 300, upscale: false);    // never enlarged
```

`contain()` returns an image of exactly the requested size. The whole picture
is scaled to fit and centred, and the margins are transparent. A picture smaller
than the canvas is enlarged to fit, as icons in a web manifest need, unless
`upscale` is `false`; then it is centred at its own size. JPEG has no
transparency, so encoded as JPEG the margins are white.

## Encode

```php
use Solo\Image\Format;

$webp = $image->encode();                            // WebP, quality 82
$jpeg = $image->encode(Format::Jpeg, quality: 85);
$png = $image->encode(Format::Png);
```

Quality runs from `0` to `100` for JPEG and WebP. PNG is lossless and ignores
it. JPEG has no transparency, so transparent areas are placed on white. PNG and
WebP keep them.

Encoding writes pixels only. EXIF, GPS coordinates, XMP and color profiles of
the source are not carried over, which also keeps a photo's location out of
what you publish.

## Use the result

```php
$result = $image->resize(width: 1200)->encode(Format::Webp);

$result->bytes;        // the encoded image
$result->size();       // its length in bytes
$result->mimeType();   // "image/webp"
$result->extension();  // "webp"
$result->width;        // 1200
$result->height;       // 900

$storage->put("photos/{$id}.{$result->extension()}", $result->bytes);
header('Content-Type: ' . $result->mimeType());
```

The MIME type and extension come from the format that was encoded, not from the
source file.

## Make several variants from one source

```php
$source = Image::fromBytes($bytes);

$variants = [
    'thumb' => $source->resize(width: 320)->encode(Format::Webp),
    'card' => $source->resize(width: 800, height: 800)->encode(Format::Webp),
    'full' => $source->resize(width: 2400)->encode(Format::Jpeg, quality: 85),
];
```

The source is decoded once and never changes, so the order of the variants does
not matter. Each resized variant holds its own pixels until it goes out of
scope. Let `$source` go when the last variant is made: a decoded photo takes
about 4 bytes per pixel.

Resizing reads every pixel of the image it is called on, so a small variant made
from a large one costs a fraction of one made from the source:

```php
$large = $source->resize(width: 1400);
$medium = $large->resize(width: 800);
$small = $medium->resize(width: 300);
```

For a 12-megapixel photo and five widths from 1400 down to 150 that halves the
time. The pictures differ from the direct ones only in fine detail, but rounding
happens at every step, so the side that follows from the aspect ratio can come
out a pixel smaller.

## Animated input

```php
$still = Image::fromBytes($bytes, firstFrame: true);
```

With `firstFrame: true`, an animated PNG or WebP gives its first frame as a
still image. For a PNG that is its default image, the one viewers without
animation support show. A WebP frame smaller than the animation's canvas keeps
its place on a transparent canvas of the full size. Limits apply to the canvas.

Without it, animation raises `AnimatedImageException`. Still images decode the
same either way.

## Keep only smaller results

```php
use Solo\Image\Optimizer;

$optimizer = new Optimizer();
$result = $optimizer->optimize($bytes, Format::Webp, quality: 82, maxWidth: 1600, maxHeight: 1600);

if ($result !== null) {
    $storage->put("{$name}.{$result->extension()}", $result->bytes);
} else {
    $storage->put($originalName, $bytes);
}
```

`optimize()` decodes, resizes within the bounds if any are given, encodes, and
returns the result only when it is strictly smaller than `$bytes`. `null` means
"keep what you have". It does not mean the input already has the requested
format or size. When the format, the bounds or the removal of metadata are
required, call `Image::fromBytes()`, `resize()` and `encode()` yourself.

Invalid, oversized and animated input raises the same exceptions as
`Image::fromBytes()`. It never turns into `null`.

## Limit the input

```php
use Solo\Image\Limits;

$limits = new Limits(maxBytes: 10 * 1024 * 1024, maxPixels: 12_000_000);

$image = Image::fromBytes($bytes, $limits);
$optimizer = new Optimizer($limits);
```

The defaults are 20 MiB of encoded input and 20 million pixels. Both limits are
checked before GD allocates anything: the pixel count is read from the image
headers. A file of a few kilobytes can declare a picture of hundreds of
megapixels, and the pixel limit is what stops it.

Limit uploads in your web server and PHP settings as well, so oversized bodies
never reach a string in memory.

## Handle errors

```php
use Solo\Image\Exception\ImageException;
use Solo\Image\Exception\ImageTooLargeException;

try {
    $image = Image::fromBytes($bytes);
} catch (ImageTooLargeException) {
    return $response->withStatus(413);
} catch (ImageException) {
    return $response->withStatus(422);
}
```

Every failure caused by the image extends `ImageException`: bytes that are not
an image, an unsupported format, an animation, an image over the limits. A
mistake in the calling code, such as a resize bound of zero or a quality of
`150`, raises PHP's `InvalidArgumentException` instead.

## Further reading

- [API reference](./api.md): every type and method.
- [Behavior in detail](./details.md): orientation, transparency, rounding, memory and damaged files.
