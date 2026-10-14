# PDFToolkit PDF to HTML

[English](README.md) · [Français](README.fr.md)

Derive semantic HTML from tagged PDF using explicit PHP objects, following
**Deriving HTML from PDF 1.2** (PDF Association, September 2026).

This repository was created specifically to implement the rules in
[Deriving_HTML_from_PDF_1-2.pdf](resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf).
The current implementation is **partial**. The [coverage guide](docs/coverage.md)
records tested mechanisms and the technical work that remains.

This optional package keeps derivation outside the PDF core. It exploits logical
structure and Unicode content rather than guessing reading order from character
positions. It does not reproduce page layout or promise conversion of every PDF.

## Install

```sh
composer require "pdftoolkit/pdf-to-html:^0.1.2"
```

Requires 64-bit PHP 8.3+ with DOM and mbstring. CI checks PHP 8.3, 8.4 and 8.5.
Composer installs the PDF core and reader as dependencies; installing the core
alone does not install this module.

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
- [Security](docs/security.md): budgets, HTML/CSS/URL validation and active content.
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
