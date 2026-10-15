# HTML derivation architecture

[English](architecture.md) · [Français](fr/architecture.md)

## Separate file reading from semantic processing

`PDF\Reading` owns PDF syntax, cross-references, streams and bounded resolution.
`PDF\Html\Reading\ReadPdfSource` adapts a read document to
`PDF\Html\Model\TaggedDocument`. Its file-input scope is smaller than the source
model an application can assemble directly.

The core keeps its PDF objects and writer. This optional module owns HTML
derivation; neither the core nor the reader depends on it.

## Retain explicit source objects

A `TaggedDocument` contains structure, role maps, class attributes, language, title
and associated files. `StructureElement` retains its role, namespace, properties,
attributes and ordered children. Text, marked content, graphics, images, links
and widgets represent distinct information. Resolution belongs to one document,
without singleton, global registry or implicit resource lookup.

`GraphicProgram` retains an instruction range, original operands/resources,
page box, matrix and enclosing Form invocation. Content nodes refer to those
programs instead of reducing a path/shading to its painting-operator name.
The retained page context supplies its transparency group and the document's
optional-content configuration when selected graphics are exported.

[`PageTransform`](../src/Reading/PageTransform.php) is an explicit readonly
`PDF\Html\Reading` object constructed from a `ReadPage`. Its public `matrix`
converts default page user space into physical PDF points using Rotate and
UserUnit. An absent or direct/indirect null UserUnit means 1; other values must
be positive and finite. Reading and export share this transform so explicit
page-coordinate viewports do not accidentally receive a Form-local matrix.

`ParentTreeValidator` compares forward associations with inverse
StructParent/StructParents entries. Its permissive/strict mode chooses between
diagnostics and errors for inconsistencies; cycles and budgets remain errors.
It checks association consistency, not every WTPDF requirement.

`StructureProperties` distinguishes Lang, ActualText, Alt, E, IDs, classes and Ref.
A replacement string is not the same as an accessible description.

## Derive semantic output

`HtmlDeriver` coordinates role resolution, attributes, content and HTML content
model repair. Explicit HTML nodes precede serialization. Derivation follows
logical child order; namespace-aware roles are resolved before selecting HTML.

HTML cannot directly nest every valid PDF structure. Repairing its content model
is separate from reproducing a PDF layout. Headings, captions, labels, lists,
tables, figures and widgets therefore have their own processing rules.

`AssociatedFileSelection` applies the shared policy before interpreting native
content. A structural pass records ActualText and accepted Alternative/Supplement
before any shared page is interpreted. Alternative replaces descendants;
Supplement replaces direct content and keeps substructure. A blocked association
leaves the original available.

`PageContentIdentifiers` associates navigation identifiers with the actual
derived content parent of each page. Replacements preserve the correct source
anchors rather than assigning them to unrelated content.

## Return evidence with the result

`DerivationResult` returns HTML, CSS, assets and diagnostics. Applications choose
how to persist and expose them. Derivation does not fetch URLs, open arbitrary
source filenames or execute scripts as a side effect.

With trusted active content enabled, `ScriptAssetEmitter` moves script source
into content-addressed `pdf-script-<sha256>.js` assets and emits `script[src]`.
Externalization preserves JavaScript grammar even when the source contains an
HTML closing-script sequence. It does not sanitize JavaScript. The caller must
write all returned assets beside the HTML or consistently remap their URLs;
the CLI example performs those writes explicitly.

Library-owned form operations use a separately enabled `FormActionPolicy`.
Their runtime does not require embedded PDF scripts. `FieldSelection`,
`ResetFormAction` and `SubmitFormAction` preserve absent/empty selections,
exclusion, `/DV` and export options. `WidgetBinding` identifies each widget's
final HTML control while values remain shared by PDF field name.

MathML fragments use namespace-aware vocabulary filtering. Presentation and
Content MathML can be preserved; preserving markup does not supply a browser
rendering engine, mathematical evaluation or a polyfill. Extended vocabulary
outside the supported Core subset produces a diagnostic. See the exact
[coverage and browser boundaries](coverage.md).

Read [coverage](coverage.md) for model/file/runtime distinctions and
[security](security.md) before processing untrusted documents.

## Configure independent limits

`ReaderLimits` bounds PDF syntax and streams. `ReadingLimits` additionally bounds
source adaptation: depth, nodes, instructions, content/text bytes, mappings,
assets and page count. `DerivationLimits` bounds semantic traversal, output bytes,
assets and role-map chains. Each conversion owns fresh budgets and caches.

`PdfToHtml` coordinates these injectable stages. A `DocumentReader` configured
with `StandardPasswordAuthentication` authenticates and decrypts supported input
before adaptation; `ReadPdfSource` carries its verified effective permissions
into the immutable source model. An optional `AuthenticationProvider` remains a
separate application capability. Merely retaining an `/Encrypt` dictionary or
constructing a permission object does not implement decryption. See
[native authentication](authentication.md) for credentials and explicit limits.

The default source adapter receives the deriver's policy and limits. Applications
wiring separate stages pass the same objects explicitly; see [policies](policies.md).
The separate authentication port is dispatched only by the reader's typed `EncryptedPdfException`
for verified `/Encrypt`; adaptation/filter failures never request it by text matching.

## Supply interpretation and rendering capabilities

`TaggedPdfSource` is the public interpretation port implemented by `ReadPdfSource`.
Inject another implementation into `PdfToHtml` to obtain the same immutable model
from an independently interpreted document without replacing semantic derivation.

The optional third `HtmlDeriver` argument is `ContentRenderer`: an application
engine receives `GraphicContent` or a `ContentGroup` and returns `ImageContent`
or an explicit null result. `DerivationPolicy::preferVisualFigures` defaults to
false, preserving semantic figure content; enabling it requests group rendering
through that supplied engine. It does not install or launch a renderer.

`RasterContentRenderer` coordinates `GraphicPdfExporter` and an application-owned
`GraphicsRasterizer`. Export preserves selected programs and the state needed
for clipping/text advancement, remaps resources and creates an isolated one-page
PDF. The application chooses the engine and returns its pixels. Real Poppler
checks verify the export independently; application integration stays explicit.
Export retains inherited text rendering/clipping state, excludes nested artifacts
and preserves the effective source PDF version. Page programs default to the
physical CropBox; standalone Form roots retain their XObject boundary, BBox,
Group and OC membership and use transformed Form bounds. Their matrix is composed
with the page transform once. A structure
BBox or explicit page-coordinate `GraphicViewport` can override the output bounds.
Instruction, byte and object budgets are cumulative within the export; actual
traversal depth is capped too. See the [graphics guide](graphics.md).

`InlineImageBoundaryProvider` extends native codec-boundary handling without
heuristic binary `EI` searching. The provider interprets the exact payload
boundary and consumes the supplied source budget.

The optional fourth argument is `PDF\Html\Forms\AppearanceRenderer`, which
converts explicit widget-appearance nodes to HTML nodes. Reading a PDF appearance
stream and implementing a visual appearance engine remain different tasks.
Both capabilities belong to the application, with its chosen resources and
execution policy. The package launches no external process implicitly.
