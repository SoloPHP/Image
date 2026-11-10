# Public API reference

This reference describes Solo Image 1.0: PHP 8.3+, the `gd` and `exif` extensions and the `Solo\Image` namespace. [Getting started](index.md) walks through the common tasks, and [Behavior in detail](details.md) explains the rules below.

The public API consists of five types and the exceptions. `Image`, `EncodedImage`, `Limits` and `Optimizer` are `final` and `readonly`. Classes under `Solo\Image\Internal` are implementation details and can change in any release. Exception messages are not part of the compatibility promise.

| Type | Role |
|---|---|
| `Image` | A decoded still image: its size, resized copies and encoding |
| `EncodedImage` | Encoded bytes with their format and pixel dimensions |
| `Format` | The formats that can be read and written: JPEG, PNG and WebP |
| `Limits` | The largest input, in bytes and in pixels, that will be decoded |
| `Optimizer` | Re-encodes an image and offers the result only when it is smaller |
| `Exception\ImageException` | Base of every failure caused by the image |

## Image

An image is created with `fromBytes()` only. It is immutable: no method changes its pixels.

### fromBytes()

```php
public static function fromBytes(string $bytes, Limits $limits = new Limits()): self
```

Decodes JPEG, PNG or static WebP, detected from the contents, and applies the EXIF orientation. It refuses input for these reasons:

| Reason | Exception |
|---|---|
| The input is longer than `$limits->maxBytes` | `ImageTooLargeException` |
| The bytes are empty or not a recognizable image | `InvalidImageException` |
| The image is not JPEG, PNG or WebP | `UnsupportedFormatException` |
| A PNG or WebP container is cut short | `InvalidImageException` |
| The PNG or WebP is animated | `AnimatedImageException` |
| Width × height is more than `$limits->maxPixels` | `ImageTooLargeException` |
| GD is built without the codec for this format | `UnsupportedFormatException` |
| GD cannot decode the pixels | `InvalidImageException` |

The byte limit is checked before anything is parsed, and every reason but the last is found from headers and chunk boundaries, before a raster is allocated. When several reasons apply to one input, which of them is reported is not part of the contract.

### width() and height()

```php
public function width(): int
public function height(): int
```

The size in pixels after orientation: a photo stored sideways with an EXIF rotation reports the size it is displayed at.

### resize()

```php
public function resize(?int $width = null, ?int $height = null): self
```

Returns an image that fits within the bounds, with the aspect ratio kept. With one bound, the other side follows from the aspect ratio. With two, the image fits inside the box. A side is never enlarged; the derived side is rounded down and is at least one pixel.

When the image already fits, the same instance is returned. Otherwise the result has its own pixels and the source is unchanged.

Throws `InvalidArgumentException` when both bounds are `null` or a bound is less than `1`. Throws `ImageException` when GD cannot allocate or resample the result.

### encode()

```php
public function encode(Format $format = Format::Webp, int $quality = 82): EncodedImage
```

Encodes the pixels and returns them with the format and dimensions.

| Format | Quality | Transparency |
|---|---|---|
| `Format::Jpeg` | `0` to `100` | Composited on white |
| `Format::Png` | Not used: lossless, compression level 6 | Kept |
| `Format::Webp` | `0` to `100`, always lossy | Kept |

No metadata of the source is written. The call does not touch PHP's output buffer.

Throws `InvalidArgumentException` when `$quality` is outside `0` to `100`, for every format. Throws `UnsupportedFormatException` when GD is built without the codec, and `ImageException` when encoding fails, for example for a WebP wider or higher than 16383 pixels.

## EncodedImage

The result of `Image::encode()` and `Optimizer::optimize()`. Applications receive it and do not construct it.

| Member | Type | Meaning |
|---|---|---|
| `$bytes` | `string` | The encoded image |
| `$format` | `Format` | The format that was encoded |
| `$width`, `$height` | `int` | Pixel dimensions |
| `size()` | `int` | Length of `$bytes` |
| `mimeType()` | `string` | `image/jpeg`, `image/png` or `image/webp` |
| `extension()` | `string` | `jpg`, `png` or `webp`, without a dot |

## Format

A string-backed enum whose values are MIME types.

| Case | Value | `extension()` |
|---|---|---|
| `Format::Jpeg` | `image/jpeg` | `jpg` |
| `Format::Png` | `image/png` | `png` |
| `Format::Webp` | `image/webp` | `webp` |

```php
public function mimeType(): string
public function extension(): string
```

`mimeType()` returns the case's value. As with any backed enum, `Format::tryFrom('image/webp')` turns a MIME type into a case, or `null` for a type that is not supported.

## Limits

```php
public function __construct(
    public int $maxBytes = 20_971_520,
    public int $maxPixels = 20_000_000,
)
```

The largest encoded input, in bytes, and the largest source image, in pixels, that will be decoded. Values equal to a limit are allowed. The pixel limit applies to the source, however small the requested result is.

Throws `InvalidArgumentException` when a limit is less than `1`.

## Optimizer

### __construct()

```php
public function __construct(Limits $limits = new Limits())
```

The limits apply to every image the optimizer is given.

### optimize()

```php
public function optimize(
    string $bytes,
    Format $format = Format::Webp,
    int $quality = 82,
    ?int $maxWidth = null,
    ?int $maxHeight = null,
): ?EncodedImage
```

Decodes `$bytes`, resizes within the bounds when at least one is given, and encodes. Returns the result when it is strictly smaller than `$bytes`, and `null` otherwise.

`null` says only that the candidate was not smaller. The input may be in another format or larger than the bounds.

Throws everything `Image::fromBytes()`, `Image::resize()` and `Image::encode()` throw. Input that cannot be processed is never reported as `null`.

## Exceptions

All of them live in `Solo\Image\Exception`.

| Exception | Extends | Thrown when |
|---|---|---|
| `ImageException` | `RuntimeException` | GD cannot allocate, resize or encode an image. Also the base of the rest. |
| `InvalidImageException` | `ImageException` | The bytes are not an image, a container is cut short or the pixels cannot be decoded |
| `UnsupportedFormatException` | `ImageException` | The format is not JPEG, PNG or WebP, or GD lacks its codec |
| `AnimatedImageException` | `ImageException` | A PNG or WebP is animated |
| `ImageTooLargeException` | `ImageException` | The input is over the byte or the pixel limit |

Invalid arguments raise PHP's `InvalidArgumentException`, which is not an `ImageException`.
