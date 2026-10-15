# Preserve PDF graphics and render complete figures

[English](graphics.md) · [Français](fr/graphics.md)

Section 4.4 distinguishes semantic reuse from visual fidelity. A chart or
infographic must be processed as a group when its appearance matters. Rendering
its paths separately from its text can change the result.

## Preserve the source program

`ReadPdfSource` attaches immutable `GraphicProgram` objects to content nodes.
Each object retains the container's shared typed instructions, its resources,
source resolver, physical bounding box and inclusive selected instruction range.
Called Form programs also retain the calling `Do` instruction and their original stream.
These objects keep colors, paths, clipping, transformations, shadings and font
state available to a rendering engine.

`GraphicPdfExporter` creates an isolated PDF in memory. It replays graphics state
and preserves text advances using invisible unselected text. Inherited text
rendering/clipping mode is preserved, including across Form calls. Nested artifacts
and unselected paints are omitted; partially selected Form streams are substituted.
Page transparency groups and optional-content membership/default visibility remain
available. The export keeps the effective source PDF version, rather than forcing 2.0.
Compressed image and font resources are imported without decoding or recompressing them.
The export has no AcroForm, document actions or PDF ECMAScript. External stream
files are refused. Object, instruction and byte budgets are cumulative across
the export; actual resource/caller traversal depth is also bounded.

A standalone Form reached through an object reference remains a Form XObject
in the exported PDF. Its original `BBox`, transparency `Group` and
optional-content `OC` membership stay on that object. This preserves its true
clipping boundary even when a matrix shears the Form; replacing that clip by an
axis-aligned page rectangle would expose paint outside the Form. The wrapper
applies the composed page/Form transform and calls the Form once. Its cloned
dictionary omits `Matrix`, which that wrapper has already applied.

```sh
php examples/02-export-figure.php tests/Fixtures/tagged.pdf figure.pdf
```

The CLI chooses its input and output paths. The library's exporter returns a
`RenderingDocument`; it performs no filesystem writes and launches no programs.
Its viewport is measured in physical points, with page rotation and `UserUnit`
applied once. A figure may include nested Form containers under one source root;
an image cannot silently combine independent roots or pages.

[`PageTransform`](../src/Reading/PageTransform.php) is the shared readonly object
for this conversion. Construct it from a `ReadPage`; its public `matrix` maps
default page user space to physical PDF points. UserUnit is 1 when absent or null,
including an indirect null; other values must be positive and finite. The Form's
matrix is composed with the page transform once; export does not apply it again
when calling the root XObject.

For page programs, the default viewport is the source page's physical CropBox;
standalone Form programs use their transformed Form bounds. A structure `BBox`
can supply the figure viewport, or an application can pass `GraphicViewport`
explicitly through `ContentGroup`. Those source-coordinate bounds are transformed
once by the page matrix, including for a standalone Form: explicit viewports are
in page coordinates, not the Form's local coordinates. The exporter does not
discover a tight rectangle from painted pixels; choose an explicit viewport when
a smaller crop is required. Native reading retains the source page context;
an application constructing a standalone Form model must supply that context
when it requests an explicit page-space viewport, or export fails explicitly.

## Supply the engine as an object

Implement `GraphicsRasterizer::rasterize(RenderingDocument): ImageContent` with
the rendering engine selected by your application, then inject it:

```php
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\PdfToHtml;
use PDF\Html\Rendering\RasterContentRenderer;

// $rasterizer is your explicit GraphicsRasterizer implementation.
$renderer = new RasterContentRenderer($rasterizer);
$converter = new PdfToHtml(deriver: new HtmlDeriver(
    policy: new DerivationPolicy(preferVisualFigures: true),
    renderer: $renderer,
));
$result = $converter->convert($fileBytes, 'chart.pdf');
```

The rasterizer returns portable PNG, JPEG or GIF bytes. The renderer preserves
the logical viewport dimensions independently of the raster resolution.
Choose and isolate the engine according to your application's input policy.
No renderer is started implicitly by installing the package.

The visual profile fails explicitly if its renderer is missing or declines the
group. Semantic reuse may omit irrelevant paths and shadings, as sections 4.4.1
and 4.4.5 permit; diagnostics identify those omissions. Images that cannot be
converted receive a placeholder at their logical display size.

## Independent rendering evidence

`tools/validate_graphics.py` parses exported PDFs with pypdf and renders them
with Poppler. Pixel checks verify clipping, inherited Form colors, exclusion of
other Form MCIDs, advancement across invisible text, rotation and `UserUnit`.
The PDF syntax used by these cases is authored independently of the writer.

[validate_graphics_context.py](../tools/validate_graphics_context.py) independently
parses and renders original/exported context cases. It compares complete pixel
buffers for inherited Form text clipping, page transparency and optional-content
visibility, including standalone Form object references, sheared Form clipping and
explicit viewports in page space under rotation/UserUnit; nested Artifact paint
is deliberately absent. It also checks source
PDF 1.7 preservation. [GraphicExporterContextTest](../tests/Rendering/GraphicExporterContextTest.php)
adds cumulative instruction and indirect resource-depth rejection cases, plus
direct/indirect null UserUnit exports matching its absence. These oracles prove
their exercised cases, not fidelity for arbitrary PDF pages.

Inline image aliases follow ISO 32000-2 EC3 issue3 (physical source page 283):
when an abbreviated and full parameter name coexist, the abbreviated value wins
regardless of order, including `L`/`Length`. Every source value still undergoes
syntax, directness and budget checks; duplicate decoded names remain invalid.
Only effective filter and colour-space values are interpreted. The codec/sample
endpoint remains independently checked, and following content operators survive.

Compatibility sections `BX`/`EX` now balance independently from `q`/`Q`, `BT`/`ET`
and marked-content pairs, as clarified by EC3 issue302 (physical pages 126 and
736). Unmatched or unclosed sections and operands on these zero-operand operators
fail. Nesting uses the existing depth budget. Supported instructions retain their
meaning inside a section; unrecognised instructions are ignored there while their
source program remains available. Outside a section they retain the explicit
unsupported-operator placeholder.

EC3 issue365 (physical page 38) reserves brace delimiters for Type 4 calculator functions. Content operator names retain brace bytes; operand-name parsing uses the same corrected general PDF syntax in Reader 0.6.
