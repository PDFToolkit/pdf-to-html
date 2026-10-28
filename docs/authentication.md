# Native source authentication

[English](authentication.md) · [Français](fr/authentication.md)

Version 0.5.0 requires pdf-reader `^0.6` and accepts its explicitly configured
Standard password or public-key authentication.
The default converter opens no encrypted file without a selected authentication operation. The application supplies the
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
Core 0.9 supplies the native MAC verifier used by the reader.

Reader 0.4.1 also preserves terminal Encrypt, Root and catalog Metadata identities
through bounded physical reference chains. Only the actual catalog metadata
stream is implicitly clear; page and object metadata retain their encryption.

`ReadPdfSource` receives `ReadDocument::permissions()` only after successful
authentication. It creates `AuthenticatedPermissions` using the effective mask:
for Standard authentication, the user keeps the source `/P` restrictions, while
authenticated owner access has mask `-4`. The HTML body carries `data-pdf-perms`,
and the `viewer-permissions` diagnostic records that the receiving application must
enforce these restrictions. Exporting a mask does not implement browser access
control. Unencrypted input has no fabricated permission context; reusing a
converter does not reuse permissions from a previous document.
`PdfToHtml` also checks custom source adapters: dropping or elevating a native
verified mask is a derivation error, not a silent loss of restrictions.

A wrong password raises `PDF\Reading\Security\AuthenticationFailed`; it is not
retried through another provider. Unsupported security handlers and crypt filters
remain explicit reader errors. Source PDF MACs are verified by Reader 0.4 before
protected content is interpreted. This does not validate a CMS signature
mathematically, establish certificate/TSA trust or authenticate derived HTML.

The `AuthenticationProvider` port supplies an authenticated `ReadDocument` of
the exact input bytes, before any tagged interpretation. It is dispatched only when the reader reports a
verified encrypted input through `EncryptedPdfException`; later interpretation
errors, incorrect native passwords and unsupported handlers do not trigger it
by matching message text. A port must return actual authenticated source data
and verified permissions. A different file, absent permissions or a missing MAC
under a required policy is rejected before `TaggedPdfSource::read()`.

## Public-key sources with reader 0.6

Inject `PublicKeyAuthentication` when the application has explicitly selected
CMS recipient decryption. The application supplies certificate DER and private-key
PEM bytes; the reader does not discover keys or open a credential filename.

```php
use PDF\Reading\Security\PublicKeyAuthentication;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslRecipientDecrypter;
use PDF\Security\PublicKeyPermissionByteOrder;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;

$identity = new OpenSslIdentity(
    new CertificateChain(new X509Certificate(new HexadecimalString($certificateDer))),
    $privateKeyPem,
    $privateKeyPassphrase,
);
$reader = new DocumentReader(authentication: new PublicKeyAuthentication(
    new OpenSslRecipientDecrypter($identity),
    PublicKeyPermissionByteOrder::MostSignificantFirst,
));
$result = (new PdfToHtml(reader: $reader))->convert(
    new FileBytes($pdfBytes),
    'certificate-protected.pdf',
);
```

The byte order is mandatory. `MostSignificantFirst` interoperates with the
independent pyHanko producer used by the oracle; `IsoLeastSignificantFirst`
selects the source-literal legacy interpretation deliberately. It is not an
automatic fallback. Multiple recipient decrypters can be supplied explicitly
for independent crypt filters; filter/recipient/CMS budgets remain reader limits.

`PublicKeyReadPermissions` provides the same `effectiveMask()` contract used by
the HTML adapter. Restrictions come from authenticated CMS recipient words, not
an unverified `/P` entry. Public-key bit 2 grants the effective full-access mask
`-4`; it does not represent a password-owner identity. A wrong recipient fails
with `PublicKeyAuthenticationFailed` before source adaptation, without retrying
the provider port. The application receiving the HTML still enforces permissions.

Reader 0.6 normalizes historical s3 usage rights before the adapter emits
`data-pdf-perms`: later form filling, accessibility extraction, assembly and
printing quality bits derive from R2 bits 6, 5, 4 and 3. Reserved source bits 9–12
cannot grant these rights. The raw CMS word and typed subfilter remain available
from the reader for inspection; HTML deliberately receives the effective mask.
The native reader and explicit authentication-provider paths preserve that same
mask, including when the converter is reused for another document.

Reader 0.6 retains explicit s3/s4/s5 and cipher boundaries, separate document and
embedded-file keys, and PDF MAC checks for supported AESV3/AESV4 sources.
`RequirePdfMac` applies identically to these sources. Successful CMS decryption
and a valid MAC do not establish certificate trust or validate a digital signature.
See the [reader guide](https://pdftoolkit.github.io/modules/reader/authentication.html)
for accepted combinations and resource limits.

The [public-key HTML oracle](../tools/validate_public_key_authentication.py)
creates sixteen actual tagged pyHanko sources: six with restricted/full-access
permissions and absent/standalone/signature-attached MACs, plus ten historical
s3 sources separating each broad R2 permission from reserved bits 9–12. It verifies the independent
reader's source content and permission flags, then parses the PHP converter's
HTML with HTML5 rules on both native and explicit-provider paths. It checks wrong certificates, required integrity and
converter reuse with an unencrypted source. The HTML API and supported Standard
authentication mechanisms remain unchanged.

## Require source integrity

```php
use PDF\Reading\Security\SourceIntegrityPolicy;

$converter = new PdfToHtml(
    reader: $reader,
    sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac,
);
$result = $converter->convert(new FileBytes($pdfBytes), 'protected.pdf');
```

The converter preserves or strengthens the injected reader's policy. If either
requires a PDF MAC, both native and provider paths must supply one. A permissive
injected reader is strengthened before reading the catalog. A required reader
cannot be weakened by the converter's compatible `AcceptUnprotected` default.
This default still verifies any MAC indicated by the native source mechanism;
it also accepts ordinary PDFs without a MAC. The required policy rejects such
files, including a source stripped of all encryption/MAC indications.

The reader supports Standard R5–R7, plus public-key AESV3/AESV4 with reader 0.6,
Standalone and AttachedToSig MAC locations, and document timestamps, with bounded CMS and exact original-byte coverage.
See its [MAC contract](https://pdftoolkit.github.io/modules/reader/source-mac.html)
for cryptographic and resource limits. MAC protection is tied to the encryption
key: a holder of that key can issue a new MAC. It grants no extra usage rights.
Normal HTML/CSS/URL/script safety policies still apply after source validation.

Run the [complete example](../examples/04-authenticate-source-mac.php) with the
public test fixture and explicit test password:

```console
PDFTOOLKIT_PASSWORD=native-user php examples/04-authenticate-source-mac.php
```

In PowerShell, set `$env:PDFTOOLKIT_PASSWORD = 'native-user'` before running
`php examples/04-authenticate-source-mac.php`. Production credentials come from
the application, with explicit password preparation; no fallback is guessed.

## Authentication port migration in 0.4

The port now receives the effective integrity policy and returns the source
graph, replacing the former direct `TaggedDocument` return:

```php
use PDF\Html\AuthenticationProvider;
use PDF\Reading\ReadDocument;

final readonly class AuthenticatedReaderProvider implements AuthenticationProvider
{
    public function __construct(private StandardPasswordAuthentication $authentication)
    {
    }

    public function authenticate(FileBytes $encryptedFile, string $filename, SourceIntegrityPolicy $sourceIntegrity): ReadDocument
    {
        return (new DocumentReader(
            authentication: $this->authentication,
            sourceIntegrity: $sourceIntegrity,
        ))->read($encryptedFile);
    }
}
```

Keep the original bytes and document-local authenticated decryption context.
Returning a graph read from a separately rewritten clear PDF does not preserve
source identity. Implement the reader's explicit `DocumentAuthentication`
capability when integrating a different decryptor. The provider is trusted
application code; merely implementing this port is not evidence that its custom
cryptography verifies a MAC. The converter checks its returned source identity,
permissions and required verification status, then runs the same adapter and
permission checks as the native path.

[NativeAuthenticationTest](../tests/NativeAuthenticationTest.php) uses real
qpdf-produced AES-128/R4 and AES-256/R6 tagged PDFs, user/owner credentials,
wrong-password failures and reuse with an unencrypted source. The independent
[oracle](../tools/validate_authentication.py) verifies their pinned hashes,
decrypts them with pypdf/qpdf and parses actual HTML with HTML5 rules. It also
creates a fresh encrypted object-stream document with qpdf. Public fixture
passwords are test data; no production credential appears in the manifest or
diagnostics.

[SourceIntegrityTest](../tests/SourceIntegrityTest.php) and the
[MAC/HTML5 oracle](../tools/validate_source_integrity.py) add real independently
generated tagged R6/R7 PDFs in both MAC locations, both authentication paths,
user/owner masks, wrong passwords and corrupt sources. They also reject a
provider's valid MAC graph of another file, policy downgrades and adapted
permission elevation. Certificate trust and signature mathematics are separate
capabilities and are not inferred from these checks.

## Signature validation is separate

Version 0.6.0 adds an explicit source-signature provider and actionable status UI.
Password/public-key decryption and source PDF MAC checks do not activate it.
[The signature guide](signatures.md) shows the selected-certificate integrity
adapter and the responsibilities of a complete application validation policy.
