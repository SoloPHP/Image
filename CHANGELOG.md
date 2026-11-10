# Changelog

## 1.0.0-RC2

### Added

- `Image::fromBytes(..., firstFrame: true)`: an animated PNG or WebP gives its first frame as a still image instead of raising `AnimatedImageException`. For PNG that is the default image, the one viewers without APNG support show. For WebP the first `ANMF` frame is rebuilt as a still WebP, with its `ALPH` chunk for lossy frames, and decoded; a frame smaller than the canvas is placed at its offset on a transparent canvas. Limits apply to the canvas and are checked before decoding, as for still images, and a frame whose bitstream is not the size its header declares is refused before it is decoded. Rejecting animation stays the default.
- `Image::contain($width, $height, upscale: true)`: an image of exactly `$width` × `$height` with the whole picture centred on transparent margins, as icons and fixed-size slots need. A smaller picture is enlarged unless `upscale: false`. Margins turn white in JPEG output, like any transparency.
- `Image::resize(..., fast: true)` and `Optimizer::optimize(..., fast: true)`: GD's bilinear scaler for images decoded from JPEG. It reads four source pixels per result pixel instead of all of them.

### Changed

- The default resampling is unchanged, and the speed is now an explicit choice. GD's `imagecopyresampled()` stays the default because the alternatives cost quality that no flag warned about. Measured with PHP 8.4 and bundled GD, limited to 2 CPUs, decoding, resizing and encoding WebP at quality 82:

  | Source | Target width | Default | `fast: true` |
  |---|---|---|---|
  | Noisy 4032 × 3024 photo, JPEG | 1600 | 265 ms, 311 KB | 202 ms, 589 KB |
  | Noisy 4032 × 3024 photo, JPEG | 2000 | 350 ms, 598 KB | 284 ms, 871 KB |
  | 3023 × 3646 product photo, JPEG | 1600 | 216 ms, 72 KB | 128 ms, 77 KB |

  The bilinear scaler is fast because it skips most of the source: fine detail and noise alias, so results are grainier and larger, increasingly so with stronger reductions. That suits capping the size of an upload, not making thumbnails. GD's other scalers average properly but darken the edges of transparent areas (a white square on transparency drops to 191 of 255 at its edge over white), shift the picture by a third of a pixel and differ between bundled GD and libgd 2.3. So `fast` applies only to images decoded from JPEG, the one format that cannot be transparent, and is ignored for PNG and WebP sources.
- `Image::resize()` and `Optimizer::optimize()` take a new last parameter, `fast`. Positional calls keep working.

### Internal

- `Metadata::inspect()` records the first WebP frame; `Raster` splits size fitting (`fit()`) from resampling and gains `place()`.
- PHPStan 2.3.5 is the lowest development version, so CI on the lowest dependencies runs the same analysis as on the highest.

### Docs

- First frames, `contain()`, fast resizing and deriving smaller variants from larger ones in the guide, the API reference and the details page.

## 1.0.0-RC1

### Added

- `Image`: immutable JPEG, PNG and WebP decoding and encoding using GD. One decoded source produces any number of variants.
- EXIF orientation: all eight orientations are applied, from JPEG, PNG and WebP metadata.
- `Image::resize()`: fits within explicit bounds without upscaling, preserving aspect ratio and transparency. An image that already fits is returned without copying.
- `Optimizer`: offers a re-encoded image only when it is smaller than the input.
- `EncodedImage`: encoded bytes with their format, MIME type, extension and pixel dimensions.
- `Limits`: configurable byte and pixel limits, enforced before raster decoding.
- Typed errors under `ImageException`. Animated PNG and WebP are rejected instead of being reduced to one frame.
