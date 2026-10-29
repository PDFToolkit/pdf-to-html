# Derivation policies and application capabilities

[English](policies.md) · [Français](fr/policies.md)

The reference permits security restrictions on associated content in section
4.6.1 and annex A, physical pages 62 and 68. A PDF cannot enable its own
permissions. The application supplies explicit objects for those decisions.

## Use one policy for reading and output

```php
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\PdfToHtml;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

$policy = new DerivationPolicy();
$deriver = new HtmlDeriver($policy);
$converter = new PdfToHtml(deriver: $deriver);
$result = $converter->convert(new FileBytes($pdfBytes), 'document.pdf');
```

`PdfToHtml` constructs its default source adapter with the deriver's policy and
limits. `AssociatedFileSelection` therefore decides whether an Alternative or
Supplement is applicable before reading the PDF content it replaces. Blocked
associated content does not suppress the original PDF content.

An application choosing a separate source adapter should pass the same profile
and limits. Equivalent immutable instances are accepted; incompatible values
are rejected by `PdfToHtml` at construction:

```php
$source = new ReadPdfSource(
    policy: $policy,
    derivationLimits: $deriver->limits,
    structureValidation: StructureValidationMode::Strict,
);
$converter = new PdfToHtml(source: $source, deriver: $deriver);
```

Strict mode rejects inconsistent inverse ParentTree relationships. Permissive
mode reports them in `sourceDiagnostics`, later included in derivation diagnostics.
Cycles and cumulative-budget failures remain errors in both modes. Neither mode
is a complete WTPDF, PDF/UA or accessibility certification service.

Native `StandardPasswordAuthentication` belongs to the explicitly configured
reader. It supplies verified user/owner effective permissions to `ReadPdfSource`;
wrong passwords are errors, never a retry through another port. See
[authentication](authentication.md).

`PdfToHtml` dispatches the separate authentication port only when the reader raises the typed
`EncryptedPdfException` for verified `/Encrypt`. Errors in later interpretation
and unsupported filter names never select authentication by message text. The
supplied `AuthenticationProvider` receives the effective source-integrity policy
and returns an authenticated `ReadDocument` for the exact supplied bytes, with
verified permissions. The converter checks required MAC status before adaptation
and rejects any permission removal/elevation; the receiving viewer enforces those permissions.
Absent or direct/indirect null `Encrypt` entries do not request authentication.
[AuthenticationBoundaryTest](../tests/AuthenticationBoundaryTest.php) checks
dispatch, adapter/provider failures, null entries and unverified permission rejection.

## Decide browser behavior separately

| Capability | Default | Enabled behavior |
| --- | --- | --- |
| `allowActiveContent` | false | Export trusted PDF ECMAScript and supported active associated content. This does not execute code in PHP or make source scripts safe. |
| `allowEmbeddedStylesheets` | false | Retain filtered embedded CSS without enabling PDF scripts, event handlers or remote resources. |
| `mergeCatalogHtmlSupplements` | false | Opt into the documented LaTeX interoperability profile: process all accepted catalogue HTML Supplements in PDF order instead of only the first. |
| `allowRemoteResources` | false | Emit permitted remote resource URLs. A receiving browser can fetch them; the PHP library does not. Remote HTML imports additionally require active-content permission. |
| `allowHtmlNamespace` | true | Derive recognized explicit HTML structure types under the safety filter. Disabling it requires a role mapping into another recognized namespace. |
| `formActions.allowReset` | false | Enable the library-owned ResetForm bridge, with `/DV`, selected fields, parent-name matching and exclusion. |
| `formActions.allowSubmit` | false | Enable supported HTML SubmitForm behavior. Unsupported export flags are diagnosed, not silently changed into another submission format. |
| `preferVisualFigures` | false | Request grouped visual rendering through the supplied `ContentRenderer`. Missing capabilities produce an explicit failure for the requested visual profile. |
| `includeGraphics` | true | Retain graphics for the supplied renderer. If no renderer is supplied, diagnostic output identifies omitted graphics. |

Form actions and embedded PDF scripts are independent. For example:

```php
$policy = new DerivationPolicy(
    formActions: new \PDF\Html\Forms\FormActionPolicy(allowReset: true),
);
```

This enables the library's reset implementation without exporting document,
page or widget PDF scripts. An absent `/DV` clears the PDF field value; it does
not restore its initial `/V`. Explicitly empty `/Fields` differs from an absent
selection, and a parent name matches its descendants at a dot boundary.

The script bridge shares one field value across widgets of the same name while
binding annotation events to each widget's final HTML identifier. Its supported
field/event API is documented by executable PHP/Node cases; it is not a complete
Acrobat/ISO 21757-1 implementation.

## Supply a rendering engine explicitly

Native graphic nodes retain `GraphicProgram` objects: original operands, state,
resources, instruction ranges and enclosing Form calls. `GraphicPdfExporter`
creates an isolated one-page PDF containing the requested paint operations.
Clipping and text advancement can remain necessary even when another item's
paint is omitted. PDF document scripts, AcroForm and catalog actions are not
exported into this rendering document.

The export preserves inherited text rendering/clipping mode, excludes nested
artifacts, and retains page transparency groups and optional-content visibility.
Its PDF version is the source's effective version, not an unconditional upgrade
to PDF 2.0. Page programs default to the physical CropBox; standalone Form object
references retain their Form XObject, original clipping/Matrix/Group/OC and use
transformed Form bounds. A structure BBox or explicit page-coordinate
`GraphicViewport` can override the output bounds. The page transform and Form
matrix are each applied once. No painted-pixel crop is inferred.
`PageTransform(ReadPage)` supplies the shared physical page matrix. Absent and
direct/indirect null UserUnit use 1; an explicit viewport remains in default page
user space even when the selected root is a transformed Form object reference.
Object, instruction and byte budgets are cumulative; real traversal depth is capped.

`RasterContentRenderer` receives a `GraphicsRasterizer` object supplied by the
application. That capability receives a `RenderingDocument` and returns an
`ImageContent`; it can be backed by the application's rendering engine. The
library does not choose a binary, launch a process or resolve external files.

`AppearanceRenderer` is a separate widget-appearance capability. An application
owns font availability, engine installation, execution limits and the rendering
environment. The [graphics oracle](../tools/validate_graphics.py) verifies
isolated exports with pypdf and real Poppler pixels; the engine remains an
explicit integration boundary in normal use.
The [context oracle](../tools/validate_graphics_context.py) compares original and
exported Poppler output for inherited text clipping, artifacts, transparency and
optional content. See the [graphics guide](graphics.md) for the concrete port.

## Widget interaction and signature validation

Highlighting modes and selected down/rollover appearances use library-owned CSS,
with no PDF-script opt-in. Explicit author CSS remains authoritative. An existing
content renderer is automatically adapted for appearances; a separately supplied
`AppearanceRenderer` overrides it. Native signature popovers likewise do not
require PDF scripts.

Signature validation has its own explicit `ReadPdfSource` provider, separate from
source decryption/MAC authentication and from `allowActiveContent`. With no
provider, a signed field reports `not-validated`. `SignatureOutputValidation`
reports only pinned-certificate integrity; complete trust/revocation/timestamp and
revision-permission decisions require an application policy. Read
[signatures](signatures.md) before interpreting a status as approval.

## Persist all returned assets

`DerivationResult` contains HTML, CSS, assets and diagnostics. Enabled library
form runtimes and trusted PDF scripts are externalized by `ScriptAssetEmitter`;
the returned JavaScript assets must be written alongside the HTML or consistently
remapped. Associated stylesheets and images also need their returned URLs and
filenames preserved. Asset names pass the package's storage boundary validation.

Serving those files, accepting submissions, enforcing authenticated PDF
permissions and choosing an origin/content-security policy belong to the host
application. Read [security](security.md) and inspect diagnostics before publishing.

## Retain embedded styles without enabling scripts

```php
$policy = new DerivationPolicy(
    allowEmbeddedStylesheets: true,
    mergeCatalogHtmlSupplements: true,
);
$converter = new PdfToHtml(deriver: new HtmlDeriver($policy));
```

`allowEmbeddedStylesheets` admits local `text/css` associated files and embedded
`style` elements. Every declaration still passes `CssSafetyFilter`: ordered
fallbacks such as `text-align:right; text-align:-moz-right;
text-align:-webkit-right` remain in source order. Nested `@supports` and `@media`
blocks are retained within the supported grammar and cumulative byte/token/depth
budgets. Imports, URL/image resource functions, escapes, expressions, unsupported
at-rules and malformed declarations are omitted with diagnostics. This is a
restricted CSS subset, not a complete CSS parser. Remote stylesheet links still
require `allowRemoteResources`; scripts and event handlers still require the
separate `allowActiveContent` decision. Existing callers using that broader
option retain embedded stylesheet support.

For a stylesheet associated with a structure element, the filtered local asset
is referenced by a `link rel="stylesheet"` immediately before that element
(§4.6.4.3, physical pages 65–66). Body links pass the same resource-URL checks;
the stylesheet capabilities do not authorize `import` or other link relations.
The general association rules still apply (§4.6.4.1, physical pages 63–64): an
accepted `Supplement` excludes direct content items/MCIDs but retains child
structure elements; `Alternative` replaces the whole subtree. An ignored file
does not suppress the original content. To style text without replacing it,
associate document-level CSS with the catalogue, or retain that text in child
structure elements. CSS and JavaScript positioning does not waive these rules;
associated JavaScript still requires the active-content policy (§4.6.4.4).

Strict derivation keeps the **first** accepted catalogue HTML Supplement and
ignores other associated files, following §4.2.2, physical page 12 of Deriving
HTML from PDF 1.2. Some LaTeX PDFs instead contain separate list and alignment
HTML style fragments. `mergeCatalogHtmlSupplements` deliberately selects all
accepted HTML Supplements in their PDF order and still ignores unrelated
catalogue associated files. It emits `catalog-html-supplements-merged` when it
combines multiple fragments. This opt-in interoperability profile departs from
the strict single-Supplement rule; enabling it alone grants no stylesheet,
script or network permission. All selected embedded bytes remain subject to the
reader's cumulative asset budget.

The unchanged report attachment in `tests/Fixtures/latex-associated-styles`
exercises both catalogue fragments, repeated alignment fallbacks and the Safari
`@supports` branch. Its source includes the invalid declaration `border
margin-left:1em`, which is diagnosed and omitted, not silently repaired. The
browser oracle uses the reproducible LuaLaTeX `latex-layout.pdf` fixture with
nested lists and two aligned equations, and compares filtered CSS with its
original associated fragments in Chromium and Firefox. A separate control
checks that nested `@supports`/`@media` conditions actually affect computed
styles. These finite fixtures do not establish support for every
LaTeX document, CSS feature or assistive technology.

## Keep list labels beside block content

A derived `LBody` can contain paragraphs or grouping elements. A plain inline
wrapper still lets those blocks start on the next line, leaving the label alone.
`ListLayout` supplies two CSS columns only for generated `LI` elements whose
children are a `Lbl` and a block-containing `LBody`. It preserves the source
order, identifiers, paragraphs and nested lists. The first block starts without
a default top margin. All generated rules use `:where()` with zero specificity,
so author styles on the item, marker and paragraph remain authoritative.
Flat text bodies, description lists and explicit HTML lists are unchanged.
The diagnostic `list-block-layout` records this visual adaptation, permitted
by the label-styling note in §4.3.5.4, physical page 28.

The browser oracle checks the real LaTeX list at 1200 and 320 CSS pixels: markers
remain beside the first text line, wrapped paragraphs do not overflow and the
nested list remains intact. It also checks explicit author display and margin
overrides. Mathematical cell order and source CSS are preserved; this fixture's
MathML places equation numbers on the left, and the converter does not move them
to imitate the PDF's physical placement.
