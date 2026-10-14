# Contributor instructions

- Keep PDF-to-HTML derivation in this optional module, with explicit immutable source objects and document-scoped processors. Avoid global state, singleton registries and implicit I/O.
- Follow the retained Deriving HTML from PDF 1.2 reference; record physical pages and normative decisions. Preserve the supplied PDF bytes and filename under resources/reference-documents.
- Do not claim arbitrary PDF conversion, exact visual reproduction, automatic accessibility certification or complete PDF JavaScript API support. Every excluded mechanism must have an explicit diagnostic or exception and a documented extension point.
- Untrusted input must never cause network access, filesystem reads or script execution by default. Keep budgets cumulative and validate HTML/CSS/URLs/assets before serialization.
- PHP 8.3+ on 64-bit, CI on 8.3, 8.4 and 8.5. Require meaningful independent HTML validation, security tests and PDF fixtures.
- English documentation by default with complete French counterparts. Publish guides/API in the central PDFToolkit documentation site.
- Use atomic commits, annotated immutable release tags, exact-candidate CI before releases, stable Composer dependencies and independent documentation releases.
