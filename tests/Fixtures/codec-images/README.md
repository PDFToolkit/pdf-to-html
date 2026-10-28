# Codec image inputs

Original MIT test images, not third-party document content. `provenance.json`
records every retained source/golden hash and the independent encoder provenance.
OpenJPEG component JSON files preserve all native integer samples and grids.
`gray.icc` is the original fixed-date LittleCMS Gray gamma2.2 test profile.
The RGB JPEG uses the recorded original pattern; no camera image or EXIF is used.
The two associated-opacity inputs are reproducible with
`python tools/build_codec_image_sources.py --check`; they contain valid premultiplied
samples, with either exact or non-integral inverse normalisation.

`tools/build_codec_images_fixture.py` packages these into the tagged PDF without
using the PHP decoder or HTML implementation. The source copies in this directory
are intentionally self-contained; running checks does not require another checkout.
