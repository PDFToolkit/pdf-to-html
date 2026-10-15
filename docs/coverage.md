# Deriving HTML from PDF: coverage and boundaries

[English](coverage.md) · [Français](fr/coverage.md)

Version **0.3.0** implements the derivation mechanisms of the retained reference
through native source interpretation, explicit policies and application ports.
The [clause ledger](requirements.md) records every numbered rule, its decision
and concrete evidence. Coverage is not universal PDF acceptance, browser layout
reproduction or an accessibility certification.

Source IDs and Table Headers are resolved to final HTML targets after explicit
owner overrides and associated alternatives; byte identity and author-supplied
HTML Headers remain distinct. Structured links resolve to the final replacement
ID regardless of reading order, with an anchor retained for element-less alternatives.
Page-navigation IDs identify the actual content parent, including replaced
content and widgets, rather than an unrelated empty marker.

## Reference scope

The reference is **Deriving HTML from PDF 1.2**, September 2026, a PDF Association
usage specification for Well-Tagged ISO 32000-2 files. Its original 74 physical
pages and bytes remain under `resources/reference-documents`.
`resources/reference.json` records its fingerprint and section inventory.
The printed body page number plus six gives the physical PDF page.

The algorithm derives logical content. It does not define HTML-to-PDF conversion,
OCR, inferred tagging or reconstruction of arbitrary page layouts.

## Three scopes to distinguish

1. **Semantic model**: immutable structure, Unicode text, attributes, widgets,
   associations and original graphic programs can also be supplied directly.
2. **Native file interpretation**: the adapter recovers those facts from accepted
   PDF syntax and encodings, with explicit budgets and graph checks.
3. **Application integration**: rendering engines, authentication, hosting assets,
   browser policies, submissions and permission enforcement remain explicit capabilities.

## Mechanisms verified in 0.3.0

| Reference and physical pages | Behavior | Concrete evidence |
| --- | --- | --- |
| 4.1–4.3.1, 11–16 | ParentTree inverse associations are checked alongside forward parents, MCIDs and page membership. Permissive reports inconsistencies; Strict rejects them. Cycles/budgets remain errors. | [ParentTreeReadingTest](../tests/Reading/ParentTreeReadingTest.php): page, annotation, Form MCR, reverse-only entries, keys, cycles and budgets. |
| 4.3.5/4.3.6/4.3.7, 23–52 | Indirect/nested form labels, contextual labels, classes/IDs, ActualText, Alt/E, owner precedence, Layout/list/table mappings and HTML repair. Explicit CSS beats defaults; Placement does not redefine list labels. Headers and IDs preserve the same binary identity. | [LabelDerivationTest](../tests/LabelDerivationTest.php), [StructureAttributePrecedenceTest](../tests/StructureAttributePrecedenceTest.php), [TableHeadersReadingTest](../tests/Reading/TableHeadersReadingTest.php), [HtmlBoundaryTest](../tests/HtmlBoundaryTest.php). |
| 4.3.5.10, 36–37 | First URI/GoTo in action sequences, SD precedence, separate Name/String destination spaces, binary IDs and validated views. Only selected navigation is processed. | [LinkActionsReadingTest](../tests/Reading/LinkActionsReadingTest.php): chains, shared actions, cycles, depth, IDs and actual-tree membership. |
| 4.4.2, 53 | Embedded ToUnicode plus Adobe glyph-list fallback, including Symbol, ZapfDingbats and MacExpert, multi-scalar names and supplementary Unicode. | [GlyphNameDecoderTest](../tests/Reading/GlyphNameDecoderTest.php): all pinned records, actual font vectors, Differences and expansion budgets. |
| 4.4.3, 53–54 | Inline-image data boundaries are parsed with codec-specific lengths/terminators, preserving logical dimensions. Available pixel conversions produce images; unavailable pixel conversion produces a placeholder. | [inline tests](../tests/Reading), [independent inline fixture/oracle](../tools/validate_inline_derivation.py), [ReadingScenariosTest](../tests/ReadingScenariosTest.php). |
| 4.4/4.4.1/4.4.4/4.4.5, 52–54 | Original instructions, operands, state, resources, selected ranges and Form callers are retained. Isolated export preserves inherited text clipping, page transparency/optional-content context and source PDF version; nested artifacts and unselected paints are omitted. The application supplies rasterization. | [GraphicProgramReadingTest](../tests/GraphicProgramReadingTest.php), [GraphicPdfExporterTest](../tests/Rendering/GraphicPdfExporterTest.php), [GraphicExporterContextTest](../tests/Rendering/GraphicExporterContextTest.php), [pixel oracle](../tools/validate_graphics.py), [context pixel comparisons](../tools/validate_graphics_context.py). |
| 4.4.8.3.1, 56–61 | ResetForm reads `/DV`, `/Fields`, parent-name selection and exclusion. HTML SubmitForm reads mapping names and export flags. Both have separate permissions from PDF scripting. | [FormActionsReadingTest](../tests/Reading/FormActionsReadingTest.php), [FormActionsTest](../tests/Forms/FormActionsTest.php), [Node runtime](../tests/Script/runtime.cjs). |
| 4.5/annex B, 61–62/69–71 | Field values remain shared; widget events bind to their final HTML IDs. Document/page scripts and field validation/calculation/formatting are scoped to the derived document. | [ScriptDeriverTest](../tests/Script/ScriptDeriverTest.php), [Node runtime](../tests/Script/runtime.cjs): nested forms, unrelated names, renamed controls and prototype-like IDs. |
| 4.6, 62–67 | Applicable Alternative/Supplement is selected before decoding replaced content, even on shared pages. Blocked files preserve original content. Catalog HTML supplementation ignores other payloads; EF wins over FS/URL. Assets are charged once across passes. | [AssociatedFilesReadingTest](../tests/Reading/AssociatedFilesReadingTest.php), [ReadingReplacementTest](../tests/ReadingReplacementTest.php). |

These tests supplement the original semantic HTML5 and real PDF fixture checks.
The ledger covers the other standard mappings, properties, owners and annexes.
They are executable implementation decisions, not a blanket certification claim.

## Accepted file inputs and extension ports

The native reader supports PDF 1.0–2.0 syntax and PDF 2.0 UTF-8 strings within
its accepted cross-reference/filter mechanisms. Damaged syntax or unsupported
reader mechanisms fail explicitly. Encrypted inputs can use the native reader’s
explicit Standard password operation or the separate authentication port.
The adapter preserves effective user/owner permissions; custom adapters cannot
drop or elevate a verified native mask. Wrong passwords fail before derivation.
See [authentication](authentication.md) and the
[independent qpdf/pypdf/HTML5 oracle](../tools/validate_authentication.py).

Unicode uses embedded ToUnicode codespaces, bfchar/bfrange and embedded UseCMap
inheritance or recognized simple-font encodings/Differences. Named external CMaps
are not implicitly loaded. Unmapped glyphs retain their original codes/program
for visual fallback and produce diagnostics; malformed mappings remain errors.

Inline-image interpretation supports verified boundaries for its built-in
encodings. `InlineImageBoundaryProvider` can supply an exact boundary for an
additional codec. It must use the supplied budget and place the cursor at the
encoded payload's end; the delimiter is checked. The library does not scan
arbitrary binary data for a convenient `EI` substring.

Native raster extraction supports accepted JPEG and sample encodings. Masks,
other color spaces and sample depths can use placeholder output or the explicit
rendering capability, whose original programs/resources remain available.
`GraphicPdfExporter` reconstructs an isolated PDF, and `RasterContentRenderer`
passes it to `GraphicsRasterizer`. A visual profile without its required engine
fails explicitly. No rendering binary is selected or launched implicitly.
Page programs default to the physical CropBox; standalone Form object references
retain their XObject and use transformed Form bounds, preserving BBox clipping,
Matrix, Group and OC. A structure BBox or explicit page-coordinate viewport can
override the output bounds. Each matrix is applied once; no painted-pixel crop is computed.
See [graphics](graphics.md) for the retained context and integration example.

## Policies and browser boundaries

[Policies](policies.md) describes the shared source/output profile, native form
operations, PDF script export, remote resources and rendering capabilities.
Section 4.6.1/annex A explicitly permits security restrictions on associated
content. Restrictions are deliberate choices, separate from parser failures.

The default disables active PDF content, remote resources and form reset/submit
operations. An application can enable library-owned form operations without
exporting PDF ECMAScript. Generated form runtime and enabled trusted scripts are
external JavaScript assets; write all returned assets or remap their URLs.
Scripts never execute in PHP and URLs never cause implicit PHP network access.

MathML filtering admits **192 element names** from the W3C MathML 3 RelaxNG
modules, their 129 schema attribute names and supported global/Core additions.
This is vocabulary/security filtering, not validation of every MathML grammar
constraint. Presentation, Content and elementary MathML can be retained.
Extended vocabulary reports `extended-mathml-vocabulary`; browser rendering,
mathematical evaluation and polyfills remain application responsibilities.
Foreign SVG/OpenMath XML is outside this MathML profile.

Acrobat/ISO 21757-1 API emulation, arbitrary page-layout reproduction, automatic
WTPDF/PDF/UA/WCAG certification and browser permission enforcement are not promised.
The reference leaves rendering/authentication/viewer details to implementations;
the package exposes those capabilities explicitly.

## Reproducible evidence

CI verifies PHP 8.3–8.5, PHPUnit, PHPStan, PSR-12 and Composer checks, plus:

1. [validate_reference.py](../tools/validate_reference.py): retained filename,
   900894 bytes, SHA-256, CC-BY-4.0 identity and 74 pages via an independent reader.
2. [build_reading_fixture.py --check](../tools/build_reading_fixture.py): byte-exact
   pinned pypdf fixture and manifest, created independently of the PHP writer.
3. [validate_derivation.py](../tools/validate_derivation.py): real conversion,
   independent PDF inspection and HTML5 parsing, Unicode, metadata, forms, links,
   IDs, alternatives and asset fingerprints; semantic transformations and font metrics.
4. [validate_inline_derivation.py](../tools/validate_inline_derivation.py): independent
   inline-image PDF and logical/sample-size checks.
5. [validate_graphics.py](../tools/validate_graphics.py): isolated exports parsed
   by pypdf and rendered by real Poppler, checking actual pixel positions/colors.
6. [runtime.cjs](../tests/Script/runtime.cjs): bounded JavaScript execution against
   actual derived control bindings, shared values, widget events and form operations.
7. [validate_graphics_context.py](../tools/validate_graphics_context.py): original
   and exported pixels for inherited text clipping, transparency groups and optional
   content, plus nested artifact omission and source PDF version preservation.
8. [validate_inline_fax.py](../tools/validate_inline_fax.py): independent libtiff
   CCITT payloads and Poppler checks of exact inline boundaries.
9. [validate_page_identifiers.py](../tools/validate_page_identifiers.py): HTML5
   checks of actual content parents, unique targets, navigation and field expansion.

Pinned glyph-list and inline-fixture builders are also checked without changing
their released bytes. Authentication dispatch and final Alternative/Headers
targets have dedicated regression cases in the released PHPUnit suite.

The standard input fixture retains SHA-256
`53b69e6ab8bf438c5fd896990ce26e1fbd0eb8a8a475c4a1bbd9e8e90fab23c0`.
Its one-pixel image painted at 20 PDF points derives to **27 × 27 CSS pixels**;
logical dimensions do not become the sample dimensions. Matrix cases also cover
q/Q, nested Forms, rotation, shear and UserUnit.

Central documentation shows input PDFs, rendered pages, executable PHP, actual
HTML/JSON/assets and exact released-source provenance. Tests and oracles validate
their exercised cases; the released suites are the source of truth for counts.

## Documented HTML and security adaptations

The reference and current HTML authoring rules do not agree in every case.
These decisions are explicit adaptations, with diagnostics, rather than claims
that incompatible markup can be reproduced unchanged:

| Reference, physical PDF page | Adaptation |
| --- | --- |
| Table 1, [page 21](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=21): RB maps to `rb` | Emit `span`, retain `data-pdf-se-type="RB"` and report `obsolete-ruby-base`. WHATWG classifies `rb`/`rtc` as obsolete; imported fragments using them are repaired with diagnostics. |
| Widget mappings, [pages 59–60](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=59): rich-text RV | Retain plain text with a diagnostic. HTML `input` has no children and `textarea` cannot contain rich-text element markup. |
| Widget flags, [pages 59–60](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=59): ReadOnly | Use native `readonly` where HTML provides it. Other controls retain `data-pdf-readonly` and report the application/runtime enforcement requirement; ARIA metadata alone does not prevent interaction. See `testReadOnlyChoiceUsesAnExplicitBridgeRatherThanInvalidReadonly` in the widget tests. |
| 4.4.7.3, [page 55](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=55): marked-content Alt | Emit `span role="img" aria-label="…"` instead of invalid `span[alt]`, treating the described sequence as a named atomic group. Report the adaptation and review its assistive-technology semantics. |
| Table attributes, [page 45](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=45): Scope Both | Omit unsupported `scope="both"` with a diagnostic; retain explicit Headers associations when supplied. |
| Widget mappings, [page 59](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=59): FileSelect value | Do not prefill a browser file input; omit the PDF value and report the limitation. |
| 4.6 and annex A, [pages 65–68](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=65): associated content | Scripts, imported stylesheets/SVG and active head content need an explicit policy. Imported CSS remains restricted; imports, URL-bearing declarations and expressions are filtered. HTTP-equivalent metadata such as refresh is never imported. |

HTML authorities: [obsolete features](https://html.spec.whatwg.org/multipage/obsolete.html#non-conforming-features),
[file inputs](https://html.spec.whatwg.org/multipage/input.html#file-upload-state-(type=file)),
[table scope](https://html.spec.whatwg.org/multipage/tables.html#attr-th-scope),
and [ARIA naming requirements](https://www.w3.org/TR/html-aria/#el-span).
MathML authorities: [official RelaxNG modules](https://www.w3.org/Math/RelaxNG/mathml3/),
[presentation markup](https://www.w3.org/TR/MathML3/chapter3.html),
[content markup](https://www.w3.org/TR/MathML3/chapter4.html) and
[MathML Core](https://www.w3.org/TR/mathml-core/).
See [security](security.md) before enabling an active-content policy.
