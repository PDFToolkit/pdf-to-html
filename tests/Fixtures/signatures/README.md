# Independent signature fixtures

These small tagged PDFs are assembled with raw PDF syntax and signed by the
OpenSSL CLI, without the PDFToolkit writer or signer. The accompanying RSA key
is **public test material only**. Both self-signed certificates expired in 2021;
successful mathematical verification does not assert certificate trust.

`tools/validate_signatures.py --generate-fixtures` rebuilds the PDFs using the
retained test identity. The default command checks committed SHA-256 hashes and
uses pypdf plus OpenSSL to verify the PDF objects and CMS independently. Add
`--php php` to compare these verdicts with the actual HTML signature provider.
Dependencies are included in the existing validation requirement files.

The corpus covers exact and zero-padded Contents, tampered signed bytes, an
unrelated certificate, an enlarged exclusion gap with a valid CMS, nonzero
padding, unsupported SubFilter, a subsequently appended unsigned revision, and
a valid CMS over a prefix that is not a complete PDF revision. A later copied
signature dictionary with identical CMS bytes tests physical token binding
independently of decoded string equality. The enlarged gap
and prefix cases deliberately remain mathematically valid: rejecting them must
depend on PDF binding checks, not merely failure of the CMS digest.

The later-revision case authenticates the original signed revision only. Its
visible text is changed by an unsigned update; no permission or modification
policy verdict follows from `integrity-verified`.
