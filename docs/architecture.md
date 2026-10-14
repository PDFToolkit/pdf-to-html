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

`StructureProperties` distinguishes Lang, ActualText, Alt, E, IDs, classes and Ref.
A replacement string is not the same as an accessible description.

## Derive semantic output

`HtmlDeriver` coordinates role resolution, attributes, content and HTML content
model repair. Explicit HTML nodes precede serialization. Derivation follows
logical child order; namespace-aware roles are resolved before selecting HTML.

HTML cannot directly nest every valid PDF structure. Repairing its content model
is separate from reproducing a PDF layout. Headings, captions, labels, lists,
tables, figures and widgets therefore have their own processing rules.

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

`PdfToHtml` coordinates these injectable stages. An optional
`AuthenticationProvider` is a separate application capability: encrypted input
needs authenticated source data and verified permissions. Merely setting a PDF
version, retaining an `/Encrypt` dictionary or constructing a permission object
does not implement decryption.

## Supply interpretation and rendering capabilities

`TaggedPdfSource` is the public interpretation port implemented by `ReadPdfSource`.
Inject another implementation into `PdfToHtml` to obtain the same immutable model
from an independently interpreted document without replacing semantic derivation.

The optional third `HtmlDeriver` argument is `ContentRenderer`: an application
engine receives `GraphicContent` or a `ContentGroup` and returns `ImageContent`
or an explicit null result. `DerivationPolicy::preferVisualFigures` defaults to
false, preserving semantic figure content; enabling it requests group rendering
through that supplied engine. It does not install or launch a renderer.

The optional fourth argument is `PDF\Html\Forms\AppearanceRenderer`, which
converts explicit widget-appearance nodes to HTML nodes. Reading a PDF appearance
stream and implementing a visual appearance engine remain different tasks.
Both capabilities belong to the application, with its chosen resources and
execution policy. The package launches no external process implicitly.
