# Behavior in detail

This page explains what happens between the bytes that come in and the bytes that go out, and where the limits of the library are.

## Design decisions

- **Bytes in, bytes out.** The library never opens a file, reads an upload or writes to storage. Paths, names, cache keys and HTTP responses belong to the application, which already knows where things live.
- **Images are immutable.** Nothing changes an `Image` after decoding. That is what lets one source produce many variants in any order, and what lets `resize()` hand back the same instance when there is nothing to do.
- **Still images only.** An animation reduced to its first frame is a silent loss, so animated input is an error the application can answer, unless it asks for the first frame by name.
- **Three formats.** JPEG, PNG and WebP cover uploads and delivery on the web and are present in common GD builds. Formats that GD supports only in some builds are left out.
- **No presets.** Thumbnail sizes and quality settings differ from one application to the next and stay there.
- **Quality first.** The default resampling looks at every source pixel. Faster resizing that costs quality is an option you name in the call.

## Format detection

The format comes from the first bytes of the input, as PHP's `getimagesizefromstring()` reads them. File names and declared MIME types play no part. Anything PHP recognizes as an image but that is not JPEG, PNG or WebP, such as GIF or BMP, raises `UnsupportedFormatException`. Bytes PHP does not recognize at all, SVG included, raise `InvalidImageException`.

## Orientation

Cameras and phones often store a photo sideways and record how to turn it in the EXIF `Orientation` tag. The tag is read from:

| Format | Where |
|---|---|
| JPEG | The EXIF segment |
| PNG | The `eXIf` chunk |
| WebP | The `EXIF` chunk, with or without the `Exif\0\0` prefix some writers add |

All eight values are applied, the four mirrored ones included. A missing tag, unreadable EXIF or a value outside `1` to `8` leaves the pixels as stored.

From decoding on, the image is the picture as people see it: `width()`, `height()` and the bounds of `resize()` refer to the turned image, and encoded results carry no orientation tag, so every viewer shows them the same way.

Mirroring is done while decoding. A quarter turn, which values `5` to `8` need and which is how phones store portrait photos, is the expensive part on a full-size photo, so it is done on the result of `resize()` instead: turning a 1200-pixel copy costs a fraction of turning 12 megapixels. Without a resize, the turn is done when encoding. Turning a 12-megapixel photo at full size takes longer than decoding its JPEG and needs a second raster of the same size, and a resized variant no longer pays for that.

## Animation

A PNG is treated as animated when it contains an `acTL`, `fcTL` or `fdAT` chunk. A WebP is treated as animated when it has an `ANIM` or `ANMF` chunk or the animation flag in its `VP8X` header. Both raise `AnimatedImageException` before any pixel is decoded.

With `firstFrame: true` they are decoded as still images instead:

- **PNG.** The default image, stored in the `IDAT` chunks, is decoded and the frame chunks are skipped. It is what viewers without animation support show. It is usually the first frame; an animation may also keep it out of the sequence as a separate fallback picture.
- **WebP.** The first `ANMF` frame is rebuilt as a still WebP: a lossless `VP8L` bitstream as it is, a lossy `VP8` bitstream together with its `ALPH` chunk when it has one. The rest of the frames are not decoded. The size in the frame's bitstream, which is what the decoder allocates, must be the size the frame header declares, and is checked before decoding. The frame is placed at its offset on a canvas of the animation's size, transparent around it. The animation's background color is ignored, as the WebP specification allows and as browsers do. Blending and disposal do not matter for a first frame.

The pixel limit applies to the canvas, which is what `getimagesize()` reports and what the result measures; a frame is never larger. A frame that lies outside the canvas, or an animation without frames, raises `InvalidImageException`.

## Transparency

Every decoded image becomes truecolor with a full alpha channel, whatever the source was:

- palette PNG, with one transparent color or with partial transparency per palette entry;
- truecolor PNG with a single color marked transparent;
- PNG and WebP with an alpha channel.

Alpha survives orientation and resizing. PNG and WebP results keep it. JPEG cannot, so `encode(Format::Jpeg)` composites the image on white first, without changing the image it was called on.

## Resizing

Each bound is first capped at the source size, which is why nothing is ever enlarged. The side that constrains the result takes its bound exactly, and the other is `floor(side × ratio)`, never less than `1`:

| Source | Bounds | Result |
|---|---|---|
| 4000 × 3000 | `width: 1200` | 1200 × 900 |
| 4000 × 3000 | `height: 600` | 800 × 600 |
| 4000 × 3000 | `width: 1200, height: 600` | 800 × 600 |
| 1000 × 667 | `width: 500` | 500 × 333 |
| 800 × 600 | `width: 1200` | 800 × 600, the same instance |
| 100 × 1 | `width: 1` | 1 × 1 |

Pixels are resampled with GD's `imagecopyresampled()`, which averages every source pixel covered by a result pixel, weighted by its opacity. Transparent pixels therefore add nothing to the color of the edges next to them, whatever color they hold.

### Fast resizing

`resize(fast: true)` uses `imagescale()` with GD's bilinear scaler for images decoded from JPEG. It reads four source pixels for every result pixel, so the cost depends on the size of the result rather than of the source. In a reduction by more than two, most source pixels are skipped: noise and fine patterns alias, the result is grainier and the encoded file larger. Its fixed-point arithmetic is off by up to two levels per channel. Measured on a noisy 12-megapixel photo resized to 1600 pixels wide and encoded as WebP, the whole call took 202 instead of 265 ms and the file grew from 311 to 589 KB; on a product photo with a plain background, 128 instead of 216 ms and 77 instead of 72 KB.

The option is ignored for PNG and WebP sources, which can be transparent: GD's own scalers do not weight colors by opacity, so the color stored in transparent pixels, usually black, bleeds into the edges next to them. GD's other filters in `imagescale()` were measured too and left out: they average properly, but they show the same dark edges, shift the picture by about a third of a pixel and give different results in bundled GD and in libgd 2.3.

### Exact canvas

`contain()` fits the picture as `resize()` does, with both bounds and, unless `upscale: false`, without the source size as a further bound. The fitted picture is centred on a transparent canvas of the requested size; when the margins cannot be equal, the extra pixel goes to the right or the bottom. Enlarging uses `imagecopyresampled()` as well.

| Source | Canvas | Picture inside | Position |
|---|---|---|---|
| 120 × 80 | `contain(64, 64)` | 64 × 42 | 0, 11 |
| 10 × 5 | `contain(32, 32)` | 32 × 16 | 0, 8 |
| 10 × 5 | `contain(32, 32, upscale: false)` | 10 × 5 | 11, 13 |
| 120 × 80 | `contain(120, 80)` | The same instance | |

## Encoding

- **JPEG** takes quality `0` to `100`.
- **WebP** takes quality `0` to `100` and is always lossy, at `100` too. There is no lossless WebP mode. WebP cannot be wider or higher than 16383 pixels; encoding a larger image raises `ImageException`.
- **PNG** is lossless at compression level 6 and is written as 8-bit RGBA, or as 8-bit RGB when the source was a JPEG and so has no transparency. A palette PNG re-encoded as PNG is therefore often larger than it was, and `Optimizer` answers `null` for it.

The quality argument is checked for every format, so `encode(Format::Png, 150)` raises `InvalidArgumentException` although PNG does not use it.

After encoding, the result is read back: unless it has the requested format and the image's dimensions, `ImageException` is raised. An `EncodedImage` therefore always holds a readable image.

Only pixels are written. EXIF, GPS, XMP and ICC color profiles of the source are gone, and GD does no color management. Pixels of an image in a wide-gamut profile are kept as they are but lose the profile that explains them. When color profiles, more than 8 bits per channel or original metadata matter, use another tool.

## Limits and memory

`Limits` are checks on the input, not a memory budget:

- `maxBytes` is compared with the length of the input before anything is parsed.
- `maxPixels` is compared with width × height as the headers declare them, before GD allocates a raster. The decoders refuse a file whose pixel data disagrees with its headers.

A decoded image takes about 4 bytes per pixel: a 12-megapixel photo holds about 48 MB, and the default limit of 20 million pixels allows about 80 MB for one image. Some steps need more while they run:

| Step | Extra memory while it runs |
|---|---|
| Decoding a PNG | Up to as much again as the image: about 8 bytes per pixel in all |
| `encode()` of a quarter-turned image that was not resized, orientation `5` to `8` | A turned copy of the image, made for each call |
| Palette or color-key source | A second copy of the image |
| `encode(Format::Jpeg)` of a PNG or WebP source | A copy of the image on white |
| `resize()` | The pixels of the result, kept as long as the result lives |
| `contain()` with margins | The fitted picture and the canvas, which the result keeps |
| First frame of a WebP smaller than its canvas | The frame and the canvas |

Running out of PHP's `memory_limit` is a fatal error, not an exception. Choose `maxPixels` so that a worker can decode the largest source, 8 bytes per pixel for PNG, and hold it together with its largest variant. Let images go out of scope when their variants are made.

## Damaged and unusual files

- **PNG.** Chunks are walked up to `IEND`. A chunk that runs past the end of the input or a missing `IEND` raises `InvalidImageException`. Data after `IEND` is ignored.
- **WebP.** The RIFF size and every chunk must fit in the input, otherwise `InvalidImageException` is raised. A missing padding byte after the last chunk is accepted, because encoders that omit it exist and decoders read such files.
- **JPEG.** Under PHP's default `gd.jpeg_ignore_warning=1`, GD reads damaged JPEG data leniently, as browsers do. A JPEG cut short after its headers still decodes, and the decoder fills in the part that is missing. No exception is raised for it.
- **Trailing data.** Bytes after the end of an image are ignored on decoding and are not part of what is encoded.

## The optimizer's comparison

`Optimizer::optimize()` compares the length of the encoded candidate with the length of the input and returns the candidate only when it is strictly smaller. It looks at nothing else: not the format of the input, not its dimensions, not visual quality. A smaller lossy result is not an equal picture, so choose the quality with that in mind.

## Errors

Failures caused by the input extend `ImageException` and can be answered as a group or one by one. Arguments that are wrong whatever the image is raise `InvalidArgumentException`: a limit below `1`, a resize without bounds or with a bound below `1`, a quality outside `0` to `100`.

GD's own warnings are suppressed where the library calls it and turned into exceptions. Nothing is written to PHP's output buffer.

## Testing

Tests build small images in memory and cover all eight orientations in each format, transparency through orientation, resizing and encoding, rounding of dimensions, fast resizing, exact canvases, format conversion, both limits at their boundaries, cut-short containers, animation markers, first frames of animated PNG and of lossy, lossy-with-alpha and lossless WebP, and the optimizer's answers. `composer check` validates `composer.json` and runs the PSR-12 check, PHPStan at its maximum level and PHPUnit. CI runs it on PHP 8.3, 8.4 and 8.5, and on PHP 8.3 with the lowest version of every development dependency.
