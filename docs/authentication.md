# Native password authentication

[English](authentication.md) · [Français](fr/authentication.md)

Version 0.3.1 requires pdf-reader 0.3.1 and accepts its explicitly configured
Standard password authentication. The default converter opens no encrypted
file without a selected authentication operation. The application supplies the
bytes and its credential; neither library discovers passwords or opens a
descriptive filename.

```php
use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\Saslprep;

// The application supplies the raw Unicode credential; preparation is explicit.
$reader = new DocumentReader(
    authentication: new StandardPasswordAuthentication(
        (new Saslprep())->prepare($rawUtf8Password),
    ),
);
$result = (new PdfToHtml(reader: $reader))->convert(
    new FileBytes($pdfBytes),
    'protected.pdf',
);
```

For historical Standard revisions, pass `PdfDocEncodedPassword` containing the
explicit PDFDocEncoding bytes instead of `PreparedPassword`. Their encodings are
different contracts; an arbitrary UTF-8 password is not silently reinterpreted as
historical bytes. AES backends require OpenSSL unless a different explicit
backend is selected. See the [reader authentication guide](https://pdftoolkit.github.io/modules/reader/authentication.html)
for supported revisions, crypt filters and source restrictions.

Reader 0.3.1 binds `EncryptMetadata=false` to the actual `Metadata` reference of
the document catalog. Page and object metadata still use their selected crypt
filter unless they explicitly select Identity. Indirect stream `Type` names are
resolved before choosing embedded-file versus ordinary-stream decryption.
The same reader correction covers the fixed 128-bit V4 key when global Length
is absent and ignored standard-name entries inside CF. These rules belong to
the reader; derivation receives the resulting authenticated source objects.
Core 0.8 remains compatible with the existing immutable derivation model.

`ReadPdfSource` receives `ReadDocument::permissions()` only after successful
authentication. It creates `AuthenticatedPermissions` using the effective mask:
the authenticated user keeps the source `/P` restrictions, while authenticated
owner access has mask `-4`. The HTML body carries `data-pdf-perms`, and the
`viewer-permissions` diagnostic records that the receiving application must
enforce these restrictions. Exporting a mask does not implement browser access
control. Unencrypted input has no fabricated permission context; reusing a
converter does not reuse permissions from a previous document.
`PdfToHtml` also checks custom source adapters: dropping or elevating a native
verified mask is a derivation error, not a silent loss of restrictions.

A wrong password raises `PDF\Reading\Security\AuthenticationFailed`; it is not
retried through another provider. Unsupported security handlers, crypt filters
or source PDF MAC requirements remain explicit reader errors. Native password
authentication does not verify CMS signatures, certificate trust or PDF MACs.
The HTML package never claims that it does.

The existing `AuthenticationProvider` port remains available for a separately
authenticated source model. It is dispatched only when the reader reports a
verified encrypted input through `EncryptedPdfException`; later interpretation
errors, incorrect native passwords and unsupported handlers do not trigger it
by matching message text. A port must return actual authenticated source data
and verified permissions.

[NativeAuthenticationTest](../tests/NativeAuthenticationTest.php) uses real
qpdf-produced AES-128/R4 and AES-256/R6 tagged PDFs, user/owner credentials,
wrong-password failures and reuse with an unencrypted source. The independent
[oracle](../tools/validate_authentication.py) verifies their pinned hashes,
decrypts them with pypdf/qpdf and parses actual HTML with HTML5 rules. It also
creates a fresh encrypted object-stream document with qpdf. Public fixture
passwords are test data; no production credential appears in the manifest or
diagnostics.
