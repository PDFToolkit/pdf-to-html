# Authenticated tagged source fixtures

[English](README.md) · [Français](README.fr.md)

These three original synthetic PDFs are derived from the MIT-licensed
`../tagged.pdf` with pyHanko 0.37.0 and cryptography 50.0.2, without a PHP PDF
writer. `manifest.json` records the original source and retained file hashes.
The public test credentials are `native-user` and `native-owner`.

R6 standalone and signature-attached MACs, and an R7 AES-GCM standalone MAC,
exercise native and provider conversion paths. The attached fixture's ephemeral
signing key was discarded; its certificate conveys no trust. Random encryption
and signing mean regeneration produces different valid bytes.

`tools/validate_source_integrity.py` regenerates equivalent independent sources,
checks pyHanko MAC authentication and HTML5 output, and rejects tampered sources
and wrong passwords. qpdf 12.4.2 checks R6 structure; it does not implement the
R7 cipher, whose original bytes are checked by pyHanko instead. No oracle
re-encodes input to hide interoperability problems. A source MAC does not
authenticate the resulting HTML.
