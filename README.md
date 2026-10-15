# PDFToolkit PDF to HTML

[English](README.md) · [Français](README.fr.md)

Derive semantic HTML from tagged PDF using explicit PHP objects, following
**Deriving HTML from PDF 1.2** (PDF Association, September 2026).

This repository was created specifically to implement the rules in
[Deriving_HTML_from_PDF_1-2.pdf](resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf).
Version 0.3.0 implements the reference's derivation mechanisms with explicit
security profiles, native password authentication and application-supplied rendering.
The [coverage guide](docs/coverage.md) and [clause ledger](docs/requirements.md)
record the concrete behavior, executable evidence and integration boundaries.

This optional package keeps derivation outside the PDF core. It exploits logical
structure and Unicode content rather than guessing reading order from character
positions. It does not reproduce page layout or promise conversion of every PDF.

## Install

```sh
composer require "pdftoolkit/pdf-to-html:^0.3"
```

Requires 64-bit PHP 8.3+ with DOM and mbstring. CI checks PHP 8.3, 8.4 and 8.5.
Composer installs the PDF core and reader as dependencies; installing the core
alone does not install this module.

Version 0.3.0 uses pdf-reader 0.3 and accepts core 0.7.1 or 0.8.

## Read, derive, then write

The application supplies bytes and chooses where to write the result. Reading,
semantic derivation and filesystem operations remain separate:

```php
use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

$document = (new DocumentReader())->read(new FileBytes($pdfBytes));
$source = (new ReadPdfSource())->read($document, 'document.pdf');
$result = (new HtmlDeriver())->derive($source);
$html = $result->html;
```

`PdfToHtml` can coordinate these same stages without hiding file access:

```php
$result = (new \PDF\Html\PdfToHtml())->convert(
    new FileBytes($pdfBytes),
    'document.pdf',
);
```

Its constructor accepts the reader, source adapter, deriver and an optional
authentication provider as explicit objects. Conversion still receives bytes;
the source filename is descriptive and is not opened implicitly.

For encrypted tagged files, inject a `DocumentReader` configured with
`StandardPasswordAuthentication`. The native adapter exports the authenticated
user/owner effective permissions to `data-pdf-perms`; a wrong password fails
before derivation. The existing authentication port remains available. Read the
[authentication guide](docs/authentication.md) for credential encodings and
explicit security-handler, PDF MAC and viewer-enforcement boundaries.

The default source adapter inherits the deriver's policy and limits. Associated
Alternative/Supplement content is selected before decoding the PDF content it
replaces, with the same policy used for HTML output. When wiring the stages
manually, pass the same policy to `ReadPdfSource` and `HtmlDeriver`.

Native reading validates inverse ParentTree associations in permissive mode,
returning diagnostics for inconsistencies. Use
`StructureValidationMode::Strict` on `ReadPdfSource` to reject those inconsistencies.
This is a structure-association check, not WTPDF/PDF/UA certification.

## Choose rendering and form capabilities

Logical text, Unicode mappings, structure, tables, labels, links, image XObjects
and supported inline-image encodings are read directly. Native graphics retain
the original instruction programs, state, resource graph and source context.
`GraphicPdfExporter` creates an isolated PDF containing selected paint operations;
`RasterContentRenderer` hands that document to an explicitly supplied
`GraphicsRasterizer`. The library does not install or launch a renderer.
The export retains the source's effective PDF version, transparency and optional
content context. Page programs use the physical CropBox; standalone Form object
references retain the Form XObject and its transformed bounds, clipping, matrix,
transparency and optional content. A structure BBox or explicit page-coordinate
viewport can override the output bounds. No painted-pixel crop is inferred.
See the [graphics guide](docs/graphics.md) for the executable integration example.

ResetForm uses the PDF default value `/DV`, including its absence, and honors
field selection, parent names and exclusion. SubmitForm has explicit HTML export,
selection and mapping-name behavior. Both need `FormActionPolicy` permission;
the library-owned form runtime can be enabled without exporting PDF scripts:

```php
$policy = new \PDF\Html\DerivationPolicy(
    formActions: new \PDF\Html\Forms\FormActionPolicy(allowReset: true),
);
$converter = new \PDF\Html\PdfToHtml(deriver: new HtmlDeriver($policy));
$result = $converter->convert(new FileBytes($pdfBytes), 'document.pdf');
```

PDF ECMAScript and remote resources have separate opt-ins. See
[policies](docs/policies.md) before enabling them or serving returned assets.

`DerivationResult` returns HTML, CSS, assets and diagnostics explicitly. Inspect
them before publishing the result. Write every asset beside the HTML, using its
returned filename, or remap its URLs consistently. When trusted scripts are
enabled, `ScriptAssetEmitter` returns external `pdf-script-<sha256>.js` files;
the HTML depends on those files. The immutable source model can also be built
directly by an application, independently of file parsing.

Run the real PDF example:

```sh
php examples/01-derive-tagged-pdf.php tests/Fixtures/tagged.pdf _build/derived.html
```

The script receives explicit input/output paths, writes HTML and every returned
asset to the output directory, and prints hashes and diagnostics as JSON.
The [central tutorial](https://pdftoolkit.github.io/tutorials/35-derive-html.html)
provides the input PDF, rendered pages, executable PHP and actual HTML result.

## Understand the boundaries

- [Architecture](docs/architecture.md): source objects, roles and serialization.
- [Coverage](docs/coverage.md): reference scope and file-adapter boundaries.
- [Clause ledger](docs/requirements.md): numbered rules, decisions and evidence.
- [Policies](docs/policies.md): shared profiles, forms, scripts and rendering.
- [Security](docs/security.md): budgets, HTML/CSS/URL validation and active content.
- [Authentication](docs/authentication.md): native credentials and effective user/owner permissions.
- [Retained reference](docs/reference.md): original PDF, license and fingerprint.
- [Complete API and guides](https://pdftoolkit.github.io/modules/to-html/).

The library does not execute scripts or implicitly fetch external resources.
The reference's ECMAScript rules must be read alongside the explicit security
policy, not as a promise to emulate Acrobat's JavaScript API. Semantic HTML does
not certify accessibility or PDF/UA.

## Develop

```sh
composer install
composer check
```

Follow [the release procedure](RELEASING.md) for exact-candidate CI, independent
validation and stable-consumer verification. Code is MIT licensed; the retained
PDF Association publication has its own CC-BY-4.0 license.
