# Changelog

## 1.0.0-RC1

### Added

- `Image`: immutable JPEG, PNG and WebP decoding and encoding using GD. One decoded source produces any number of variants.
- EXIF orientation: all eight orientations are applied, from JPEG, PNG and WebP metadata.
- `Image::resize()`: fits within explicit bounds without upscaling, preserving aspect ratio and transparency. An image that already fits is returned without copying.
- `Optimizer`: offers a re-encoded image only when it is smaller than the input.
- `EncodedImage`: encoded bytes with their format, MIME type, extension and pixel dimensions.
- `Limits`: configurable byte and pixel limits, enforced before raster decoding.
- Typed errors under `ImageException`. Animated PNG and WebP are rejected instead of being reduced to one frame.
