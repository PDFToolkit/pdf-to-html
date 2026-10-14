# Deriving HTML from PDF reference

[English](reference.md) · [Français](fr/reference.md)

The source is **Deriving HTML from PDF, version 1.2, September 2026**, published by
PDF Association under [CC-BY-4.0](https://creativecommons.org/licenses/by/4.0/).
The original PDF has **74 physical pages**; its filename and bytes are preserved.

[Open the original document](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf)
· [Inspect the inventory and fingerprint](../resources/reference.json).

The numbered body begins on physical page 7 (printed page 1), after six pages of
front matter. Security, ECMAScript, encrypted-file and accessibility annexes are
part of the retained scope. The original remains the reference; project
descriptions explain it without replacing its normative wording.

[Coverage](coverage.md) distinguishes model rules, accepted file inputs and
runtime responsibilities. Retaining the source does not mean every conversion
or browser capability is supported. The original stays outside Composer archives.

The [clause ledger](requirements.md) links physical-page rules to executable
evidence and marks native behavior, policies, HTML adaptations and application
ports. The security/authentication boundary uses pdf-reader's typed
`EncryptedPdfException`; rendering evidence combines independently parsed
isolated PDFs with real Poppler pixels.

Current HTML/ARIA rules and the W3C MathML vocabulary supplement the integration
decisions; they do not replace the PDF Association reference. The
[coverage guide](coverage.md#documented-html-and-security-adaptations) links the
primary HTML authorities and the official MathML 3 schemas/Core specification,
and records adaptations where literal mappings conflict with browser rules.
