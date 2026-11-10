---
layout: home

hero:
  name: Solo Image
  text: Decode once. Encode every variant.
  tagline: Immutable GD images for PHP. EXIF orientation, bounded resizing, JPEG, PNG and WebP encoding, and optimization that keeps a result only when it is smaller.
  image:
    src: /logo.svg
    alt: Solo Image
  actions:
    - theme: brand
      text: Get started
      link: /guide/
    - theme: alt
      text: API reference
      link: /guide/api

features:
  - icon: 🧭
    title: Photos the right way up
    details: All eight EXIF orientations are applied on decoding, mirrored ones included, from JPEG, PNG and WebP metadata. Width and height describe the picture as people see it.
  - icon: 🧊
    title: Immutable images
    details: An image never changes after decoding. One source produces a thumbnail, a preview and a full-size copy, each with its own pixels.
  - icon: 📐
    title: Bounded resizing
    details: Fit inside a width, a height or both, or into an exact canvas with transparent margins. The aspect ratio is kept. An image that already fits is returned without copying.
  - icon: 🪶
    title: Smaller or nothing
    details: Optimizer offers a re-encoded image only when it is strictly smaller than the input. Otherwise you keep the original bytes.
  - icon: 🛡️
    title: Limits before decoding
    details: Byte and pixel limits are read from the headers and enforced before GD allocates a raster. Animated PNG and WebP are rejected, or give their first frame when you ask.
  - icon: 📦
    title: Bytes in, bytes out
    details: No files, uploads, storage or framework. The library takes a string and returns a string with its format, MIME type, extension and dimensions.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #d946ef 0%, #f97316 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #d946ef30 0%, #f9731630 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Turn an upload into two variants

```php
use Solo\Image\Format;
use Solo\Image\Image;

$image = Image::fromBytes($bytes);

$thumbnail = $image->resize(width: 320)->encode(Format::Webp);
$preview = $image->resize(width: 1200, height: 900)->encode(Format::Jpeg, quality: 85);

$storage->put("photos/{$id}-thumb.{$thumbnail->extension()}", $thumbnail->bytes);
$storage->put("photos/{$id}.{$preview->extension()}", $preview->bytes);
```

`fromBytes()` detects the format from the contents, checks the limits and turns the photo the right way up.
Each variant has its own pixels, and `$image` stays as it was decoded. Read the
[getting-started guide](./guide/index.md) for optimization, limits and errors.

## Installation

PHP **8.3+** is required, with the `gd` and `exif` extensions. GD must be built with support for the formats you
use: JPEG, PNG and WebP.

Version 1.0 is a release candidate, so Composer needs to be told that a pre-release is acceptable:

```sh
composer require "solophp/image:^1.0@rc"
```

Continue with the [getting-started guide](./guide/index.md).
