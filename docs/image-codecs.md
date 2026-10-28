# Decoded image planes and portable browser images

[English](image-codecs.md) · [Français](fr/image-codecs.md)

The HTML source reader consumes `ReadDocument::imageData()` for terminal DCT,
JPX and JBIG2 filters. The Reader's immutable image retains each component's
precision, signedness, sample grid, origin, alpha role and colour metadata.
`PortableImageReader` and `DecodedImagePng` interpret the PDF image dictionary
before constructing a browser asset. Decoding capability and colour rendering
remain separate: a decoded image is not automatically representable in PNG.
Applications may inject a Reader `ImageCodecs\Decoder` through `DocumentReader`;
codec availability follows the Reader's configured native/PHP policy. The HTML
layer performs no file acquisition, network request or process launch of its own.

## PDF interpretation

ISO 32000-2:2020 §7.4.8, Table 13 (physical page 64) defines DCT `ColorTransform`
and APP14 priority. §7.4.9 (physical pages 65–67), together with Table 87
(physical pages 273–275), defines JPX colour, precision and opacity:

- PDF width and height must agree with the decoded image.
- JPX `BitsPerComponent` is ignored: precision comes from the decoded planes.
- An explicit PDF `ColorSpace` overrides embedded JPX colour information.
  An absent colour space selects the JPX colour information; `Decode` is then
  ignored, except for stencil images, which require the explicit graphics renderer.
- Gray/RGB ICC profiles are embedded byte for byte in PNG `iCCP`. The profile
  stream's `N`, default component ranges, header and tag bounds are validated.
  This is container/provenance validation, not a colour-profile certification.
- `SMaskInData=0` ignores the JPX opacity plane. Values 1 and 2 require one opacity
  channel associated with every colour channel, with the corresponding straight
  or premultiplied type. JPX chroma-key transparency is ignored as PDF requires.
- Explicit identity and inverted `Decode` pairs are supported on decoded codec
  planes with a PDF colour space. Other mappings retain the original graphic
  program and require a renderer.

The PNG path preserves legal Gray depths 1/2/4/8/16 and RGB depths 8/16. Mixed
legal component depths and packed RGB at 1/2/4 bits expand only by exact integer
normalisation to a common PNG depth. Alpha requires PNG8 or PNG16. Every source
sample remains exactly representable; no plane is truncated to eight bits.
Common nonzero origins are translated without moving samples. Distinct grids,
signed planes, unsupported precision, Indexed/CMYK/Lab/YCbCr interpretations,
non-sRGB RGB enumerations, non-default ICC ranges and external masks require the
explicit renderer. The Reader's original planes remain available to that application.

Premultiplied alpha is removed only when division is exact at the selected PNG
depth. Fully transparent samples become transparent black; their hidden colour
has no visible contribution. Non-integral division produces a diagnostic rather
than an approximate asset. Normalised multiplication for mixed supported depths
is also exact. Per-channel work, output sample storage, scanlines, compression,
ICC and final asset buffers consume document-scoped cumulative budgets.

## JPEG passthrough

JPEG is preserved only when its frame dimensions, components, eight-bit precision
and browser colour interpretation agree with the PDF. `ColorTransform=0` can
therefore require decoded PNG even for an ordinary three-component JPEG. APP14
is authoritative in PDF; browser JFIF precedence and ASCII RGB component IDs can
still make passthrough unsafe. Embedded ICC and EXIF metadata also prevent direct
reuse, because a browser could apply them independently of the PDF colour space
or placement matrix. Filter chains and external sources always use Reader's
prepared stream path. The original encoded source remains in `GraphicProgram`.

Unsupported representations produce an explicit source diagnostic and a
size-preserving placeholder, or are handled by the application's
[`ContentRenderer`](graphics.md). This is the conversion-unavailable disposition
in Deriving HTML from PDF 1.2 §4.4.3 (physical pages 53–54), not a loss of source
planes or a claim of complete browser colour equivalence.

## Independent validation

`DecodedImagePngTest` exercises exact depth promotion, PDF/JPX precedence,
metadata validation, alpha semantics, bounds and browser JPEG compatibility.
`tools/build_codec_images_fixture.py` packages 23 real image cases with pypdf.
The retained JP2/J2K source and sample goldens originate in independently run
OpenJPEG 2.5.4; two additional associated-opacity sources are generated with
Pillow/OpenJPEG by `tools/build_codec_image_sources.py`. `tools/validate_codec_images.py`
checks qpdf syntax, pypdf source hashes, HTML5 parsing, exact PNG samples, complete
ICC bytes and Pillow decoding. JPEG IDCT comparison allows one sample level,
independently of exact JPX/PNG comparisons. Four cases explicitly need rendering:
non-integral alpha division, signed samples, 12-bit samples and distinct grids.

```sh
python tools/build_codec_image_sources.py --check
python tools/build_codec_images_fixture.py --check
python tools/validate_codec_images.py --php php --export _build/codec-images --report _build/codec-images-validation.json
node tools/validate_codec_images.cjs _build/codec-images _build/codec-images-browser
```

The codec oracle explicitly uses the optional PHP decoder; install the published
codec package for this validation. `--bootstrap` is tooling-only for testing a
local candidate, never a production dependency mechanism. The browser harness
uses Chromium and Firefox with network requests blocked. Ordinary pixels are
compared on a white canvas; PNG16-to-canvas8 rounding is separate from exact
sixteen-bit file verification. For ICC cases, the HTML PNG must match an
independently encoded Pillow PNG with identical samples and profile **exactly in
the same browser**. LittleCMS comparison is recorded separately: the tested
Firefox display differs by up to 9/255 from LittleCMS on the retained Gray ICC
profile, while the profile and same-browser witness are exact. Cross-CMS pixel
identity is not claimed.
