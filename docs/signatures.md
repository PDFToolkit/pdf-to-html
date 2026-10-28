# Validate PDF signatures and explain their status

[English](signatures.md) · [Français](fr/signatures.md)

Deriving HTML 1.2, §4.4.8.3.1, Table 5, physical pages 57–58, asks for the source signature's
status to be available from its derived control. Version 0.6.0 provides an
explicit validation port and a native HTML popover. This information concerns
the original PDF: conversion does not sign or authenticate the HTML.

## Supply validation explicitly

`ReadPdfSource` accepts an optional `SignatureValidationProvider`. For each signed
field it supplies a `SignatureValidationRequest` containing the exact
`ReadDocument`, signature dictionary and field name. Return a
`SignatureValidationResult` for that same request object, a
`SignatureValidationStatus` and a nonempty UTF-8 explanation. `assertFor()` rejects
a result bound to another request, even when its field name is identical.

The supplied `SignatureOutputValidation` uses the core's explicit
`SignatureOutputVerifier` and an application-selected `CertificateChain`:

```php
use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Signatures\SignatureOutputValidation;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslSignatureOutputVerifier;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;

// Both byte strings are supplied explicitly by the application.
$certificate = new CertificateChain(
    new X509Certificate(new HexadecimalString($signerCertificateDer)),
);
$validation = new SignatureOutputValidation(
    new OpenSslSignatureOutputVerifier(),
    $certificate,
);
$document = (new DocumentReader())->read(new FileBytes($pdfBytes));
$source = (new ReadPdfSource(signatureValidation: $validation))
    ->read($document, 'signed.pdf');
$result = (new HtmlDeriver())->derive($source);
```

No certificate is selected from the PDF as an implicit trust decision. This
adapter verifies supported signature mathematics against the supplied certificate;
it does not build a trusted certification path or contact a revocation service.
`SignatureVerificationSource` additionally checks two nonnegative ByteRange
segments starting at byte zero, a gap matching the exact physical Contents token,
and an endpoint at a complete physical revision. Its optional
`RevisionHistoryReader` is an explicit object. A reconstructed signature with no
retained physical provenance, an unsupported format, or a document timestamp
requires a different application provider and reports `indeterminate` here.
Directly detected malformed ranges or failed signature mathematics report
`invalid`. An incomplete physical inspection, including a reader limit, reports
`indeterminate`; it does not establish a cryptographic failure.

Run [example 05](../examples/05-verify-pdf-signature.php) with explicit local
input, signer certificate and output paths:

```sh
php examples/05-verify-pdf-signature.php input.pdf signer.der _build/signed.html
```

The CLI also accepts a single PEM certificate. It writes HTML and all returned
assets, then prints source/certificate/HTML hashes and diagnostics as JSON.
Inspect the field states in the output; completing conversion is not a claim
that every signature is valid. For reproducible local demonstration, use
`tests/Fixtures/signatures/signed-padded.pdf` and `signer.der` from that same
fixture directory. These are public test material, not trusted credentials.

## Interpret the states separately

| State | Meaning |
| --- | --- |
| `unsigned` | The source field has no signature value. The reader does not call the validation provider. |
| `not-validated` | A signature exists, but no validation result was requested/provided. |
| `integrity-verified` | The supplied adapter authenticated the covered bytes against the selected certificate. It did not validate certificate trust, revocation or later PDF changes. |
| `invalid` | The chosen provider detected a failed validation condition; read its explanation. |
| `indeterminate` | The chosen provider cannot establish a result within its capabilities. |
| `valid` | Available to an application provider that implements its declared full validation policy. The supplied integrity adapter never returns this state. |

A valid signature over an earlier revision does not establish that subsequent
changes were permitted under DocMDP/FieldMDP/UR rules. Choosing trusted roots,
validation time, revocation evidence, timestamp validation and revision-permission
analysis belongs to the application provider. That provider must explain which
checks it performed; a status enum alone is not a validation policy. Decryption,
source PDF MAC verification and viewer permissions are separate mechanisms in the
[authentication guide](authentication.md).

The button carries `data-pdf-signature-status`, `popovertarget` and
`aria-describedby`; its sibling popover contains the escaped explanation.
Pointer and keyboard activation use the browser's native popover behavior, with
no PDF JavaScript permission required. Hosts targeting browsers without native
popover support must provide an appropriate presentation fallback. Applications
constructing the immutable model directly can supply `Widget::$signatureValidation`;
legacy `signatureStatus` remains display metadata, not proof of verification.

## Reproducible evidence and limits

[SignatureOutputValidationTest](../tests/Signatures/SignatureOutputValidationTest.php)
and [SignatureValidationReadingTest](../tests/Reading/SignatureValidationReadingTest.php)
exercise exact-source binding, range failures, source-field integration and
verification states. [validate_signatures.py](../tools/validate_signatures.py)
checks committed independent PDF/CMS fixtures with pypdf, asn1crypto, cryptography, OpenSSL and the PHP
adapter. The deliberately expired self-signed test certificate demonstrates the
distinction between mathematical integrity and PKI trust; it is not a deployment
credential or a trust recommendation.

[validate_signature_popovers.cjs](../tools/validate_signature_popovers.cjs) checks
actual PHP-derived controls in Chromium and Firefox, normally with page JavaScript
disabled and network requests blocked. A separate readonly case enables the
library runtime and verifies that consulting the result remains possible without
changing the field. Its report and screenshots cover state text,
escaping, pointer and keyboard activation. A successful fixture or browser run
proves those exercised cases, not universal PDF signature or accessibility
conformance. Retain each report with its source commit and tool versions.
