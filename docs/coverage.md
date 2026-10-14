# Deriving HTML from PDF: coverage and boundaries

[English](coverage.md) · [Français](fr/coverage.md)

**Implementation status: PARTIAL.** This package implements parts of the
reference, with executable PDF-to-HTML conversion and an explicit source model.
It does not implement every rule of Deriving HTML from PDF 1.2. Version 0.1.2
corrects the specific regressions described below; the remaining technical gaps
are listed separately from deliberate security restrictions.

## Reference scope

The retained reference is **Deriving HTML from PDF 1.2**, September 2026, a PDF
Association usage specification for Well-Tagged ISO 32000-2 files. It has 74
physical pages, including front matter and annexes. It is not an HTML-to-PDF
specification or a specification for visual layout reconstruction.

`resources/reference.json` inventories its sections. Recording a section does
not prove implementation of every rule. Released code, executable tests,
independent validation and diagnostics must be considered together.

## Three scopes to distinguish

1. **Source model and semantic derivation**: applications supply explicit
   structure, Unicode content, attributes, widgets, associated files and policies.
2. **PDF file adapter**: the reader recovers those facts from accepted encodings,
   operators and mechanisms. A modeled feature does not mean that every external
   PDF using it can be ingested.
3. **Browser/runtime integration**: serving HTML, resolving assets, accepting form
   submissions and providing a JavaScript runtime belong to the application.
   Returning script bytes does not execute or emulate them.

## Verified technical gaps

These are limitations of the implementation, not mechanisms completed merely
because an object, interface or diagnostic exists. Physical PDF page numbers
refer to the retained reference. Input-structure checks rely on the ISO 32000-2
knowledge required by section 4.1; this package is not a WTPDF validator.

| Reference, physical PDF pages | Remaining gap and practical effect | Code boundary |
| --- | --- | --- |
| 4.4.3, [53–54](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=53) | Inline images (`BI…ID…EI`) are rejected. The conversion fails instead of deriving an image or a placeholder with its logical dimensions. | [ContentInstructionReader](../src/Reading/ContentInstructionReader.php). |
| 4.4/4.4.1/4.4.5, [52–54](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=52) | Native file reading keeps only path/shading painting-operator markers, losing geometry, operands, graphic state and shading resources. Injecting a renderer cannot recover those missing facts. The rendering port currently needs manually constructed source objects with sufficient data; complete native graphic-group rendering remains unimplemented. Omitting irrelevant paths/shadings is permitted by the reference, but does not implement its visual-fidelity use case. | [ContentReader](../src/Reading/ContentReader.php), [GraphicContent](../src/Model/GraphicContent.php), [ContentRenderer](../src/ContentRenderer.php). |
| 4.4.2, [53](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=53) | Simple-font encoding vectors are recognized, but fallback glyph-name-to-Unicode mappings are incomplete, notably for Symbol, ZapfDingbats and MacExpert. Without usable ToUnicode, some valid text becomes an omitted graphic node with a diagnostic. | [FontTextDecoder](../src/Reading/FontTextDecoder.php). |
| 4.6.4.1, [63–64](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=63) | An accepted Alternative can replace the source element during semantic derivation, but the file adapter still reads the content it will replace eagerly. Unsupported or malformed content that should be ignored can therefore fail the conversion before the replacement is applied. | [SourceReadSession](../src/Reading/SourceReadSession.php), [DerivationSession](../src/DerivationSession.php). |
| 4.4.8.3.1/Table 5, [57](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=57), and ISO 32000-2 ResetForm action semantics | PDF ResetForm `/Fields` selection and exclusion flags are not implemented. The current native reset button resets the entire HTML form. The source adapter does not recover the selection, so a targeted PDF action must not be assumed equivalent to this output. | [SourceReadSession](../src/Reading/SourceReadSession.php), [WidgetDeriver](../src/Forms/WidgetDeriver.php). |
| 4.1/4.3.1, [11/16](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=11), and ISO 32000-2 logical-structure prerequisites | Forward structure parents, MCIDs and page membership are checked, but the inverse associations in `ParentTree` are not validated. Passing conversion does not establish that both directions of the source's structure associations agree. | [SourceReadSession](../src/Reading/SourceReadSession.php). |
| 4.3.5.4.1, [28](../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=28) | Layout `Placement` is still applied to list labels. The reference recommends ignoring it for `Lbl` in lists; a supplied value can still change the derived label's display/float behavior. | [DerivationSession](../src/DerivationSession.php), [AttributeDeriver](../src/Attributes/AttributeDeriver.php). |

The `/Fields`, glyph fallback and ParentTree issues are not security policies.
Neither are inline-image rejection or loss of graphic data. Future work needs
implementation and regression evidence for these boundaries, not only an
additional coverage row.

## Regressions corrected in 0.1.2

- **Labels and form scope** — sections 4.3.5.4 and 4.3.5.11.1, physical pages
  28–29 and 37. [LabelDerivationTest](../tests/LabelDerivationTest.php) checks
  indirect role-mapped labels through structure/content groups, labels in nested
  interactive forms, non-interactive inner forms that must not inherit an outer
  target, explicit HTML label targets and context-specific block-label mappings.
- **Replacement before glyph decoding** — sections 4.3.6.3 and 4.4.7.2,
  physical pages 40–41 and 54. [ReadingReplacementTest](../tests/ReadingReplacementTest.php)
  builds PDF byte fixtures with incoherent Unicode mappings under ActualText.
  It checks empty and nonempty replacements on a shared page, descendant MCR/Form
  content and nested marked replacements. Unreplaced invalid mappings and
  inconsistent structure parents still fail; replacement is not a bypass for
  structural validation. This correction does not yet cover AF Alternative
  replacements, as recorded above.
- **Shared field values, distinct widget events** — annex B, physical page 71.
  [ReadingScenariosTest](../tests/ReadingScenariosTest.php),
  `testSharedFieldWidgetActionsRemainDistinctWhenReadFromPdf`, reads two widgets
  sharing one field with separate focus actions and common validation.
  [ScriptDeriverTest](../tests/Script/ScriptDeriverTest.php) and the
  [Node runtime harness](../tests/Script/runtime.cjs) check distinct focus/mouse-up
  handlers, synchronized values, rejected shared values and compatibility with
  older mixed-action source models. Widget-only scripts remain disabled in the
  safe profile and count toward cumulative output budgets.
  Immutable [WidgetBinding](../src/Script/WidgetBinding.php) objects bind the
  runtime to final HTML identifiers. The Node harness uses actual nested Form
  output and checks unrelated names, renamed controls and prototype-like IDs.
- **Explicit attribute precedence** — section 4.3.7.1, physical page 43.
  [StructureAttributePrecedenceTest](../tests/StructureAttributePrecedenceTest.php)
  checks that an explicit HTML `title` overrides structural E, that E still
  supplies the title without an override, and that explicit HTML/CSS `display`
  overrides the list body's inline default.

## Implemented mechanisms and evidence

The table describes executable behavior in this package, including explicit
application ports. Its tests do not establish universal input-file acceptance or
certification of the source document.

| Reference | Implementation | Classes | Executable evidence |
| --- | --- | --- | --- |
| 4.1–4.2 | Document title/language, head/body, class styles, form association and page-label navigation; XMP title and source metadata are read from PDF. | [DerivationSession](../src/DerivationSession.php), [DocumentMetadataReader](../src/Reading/DocumentMetadataReader.php) | [HtmlDeriverTest](../tests/HtmlDeriverTest.php): `testDocumentMetadataTextEscapingAndPageNavigation`; [ReadingSourceTest](../tests/ReadingSourceTest.php): `testIndependentPdf20StructureContentAndMetadata`. |
| 4.3.1–4.3.4 | Depth-first logical order, transitive role maps and namespace-object identity; HTML content-model repair preserves content and unique IDs. | [RoleResolver](../src/RoleResolver.php), [ContentModelRepair](../src/Html/ContentModelRepair.php) | [HtmlDeriverTest](../tests/HtmlDeriverTest.php): role chains, distinct namespace identities and cycles; [HtmlBoundaryTest](../tests/HtmlBoundaryTest.php): nested anchors/labels, captions, lists and repair budgets. |
| 4.3.5 | Heading levels, captions, labels, FENote/note roles, lists/description lists, tables, figures, MathML formulas, code, links and interactive/non-interactive forms have specific transformations. The rendering port accepts application-created content; native file graphics have the limitations listed above. | [DerivationSession](../src/DerivationSession.php), [WidgetDeriver](../src/Forms/WidgetDeriver.php), [ContentRenderer](../src/ContentRenderer.php) | [HtmlDeriverTest](../tests/HtmlDeriverTest.php): description lists, captions, inline figures and Reference/Link; [LabelDerivationTest](../tests/LabelDerivationTest.php): indirect/nested labels; [AdvancedDerivationTest](../tests/AdvancedDerivationTest.php): manually modeled visual groups and ruby adaptation; [six HTML5 cases](../tests/export-derivation-cases.php): code and MathML included. |
| 4.3.6 | Lang, ActualText including an empty replacement, Alt, E, IDs and Ref-derived links are distinct; replacements preserve page anchors and omitted targets do not create broken links. | [StructureProperties](../src/Model/StructureProperties.php), [SourceValues](../src/Reading/SourceValues.php), [DerivationSession](../src/DerivationSession.php) | [HtmlDeriverTest](../tests/HtmlDeriverTest.php): replacement/expansion and marked-content wrappers; [AdvancedDerivationTest](../tests/AdvancedDerivationTest.php): replacement anchors and suppressed targets; [ReadingScenariosTest](../tests/ReadingScenariosTest.php): binary IDs and invalid optional text diagnostics. |
| 4.3.7 | Class/direct attributes use explicit owner precedence; List, Table, Layout, PrintField, HTML/CSS/ARIA, matching NSO and UserProperties produce attributes/CSS or diagnostics. PDF points convert to CSS pixels. | [AttributeDeriver](../src/Attributes/AttributeDeriver.php), [ClassName](../src/ClassName.php) | [AttributeDeriverTest](../tests/Attributes/AttributeDeriverTest.php): owners, namespaces, layout and conversions; [HtmlDeriverTest](../tests/HtmlDeriverTest.php): class/direct precedence. |
| 4.4.1–4.4.7 | Marked text, embedded ToUnicode, supported simple-font mappings, Form XObjects and supported image XObjects are read. `cm`, Form Matrix, q/Q, page rotation and UserUnit compose logical image dimensions. Native paths/shadings retain only painting-operator markers; inline images are rejected. | [ContentReader](../src/Reading/ContentReader.php), [ContentMatrix](../src/Reading/ContentMatrix.php), [FontTextDecoder](../src/Reading/FontTextDecoder.php), [ToUnicodeReader](../src/Reading/ToUnicodeReader.php) | [ReadingSourceTest](../tests/ReadingSourceTest.php): actual PDF Unicode/Form/image content; [ReadingScenariosTest](../tests/ReadingScenariosTest.php): quote operators, state restoration, nested matrices, shear/rotation/UserUnit, unsupported images and preallocation limits; [ReadingReplacementTest](../tests/ReadingReplacementTest.php): replacement before decoding. |
| 4.4.8 | OBJR, URI/GoTo/structure targets and separate Name/String destination spaces; widget inheritance, options/export values, flags, appearances and actual Annots/CropBox visibility. Signature widgets report status without inventing validation. | [SourceReadSession](../src/Reading/SourceReadSession.php), [WidgetDeriver](../src/Forms/WidgetDeriver.php), [AppearanceRenderer](../src/Forms/AppearanceRenderer.php) | [ReadingScenariosTest](../tests/ReadingScenariosTest.php): destination identity, absent widgets and CropBox; [WidgetDeriverTest](../tests/Forms/WidgetDeriverTest.php): choice/radio/text, readonly, rich text, file inputs, submit/reset/signature and visibility. |
| 4.5 and annex B | Opt-in, document-scoped JavaScript bridge for common fields/events, validation, calculation, formatting and whole-form reset. Fields share values by name while widgets keep their own events. No PHP execution and no complete ISO 21757-1 API emulation. | [ScriptDeriver](../src/Script/ScriptDeriver.php), [DerivationPolicy](../src/DerivationPolicy.php) | [ScriptDeriverTest](../tests/Script/ScriptDeriverTest.php), [Node runtime harness](../tests/Script/runtime.cjs): script initialization, distinct widget actions, shared names, rejected values, calculations, readonly and whole-form reset. |
| 4.6 | Alternative/Supplement plans, embedded HTML/MathML/images and explicit asset ownership; URL-reference media types are inferred from path extensions. CSS, SVG, JavaScript and remote references follow separate policies. Head supplements retain safe metadata and never refresh directives. | [AssociationPlan](../src/AssociationPlan.php), [AssetCollection](../src/AssetCollection.php), [DerivationSession](../src/DerivationSession.php), [SafetyFilter](../src/Html/SafetyFilter.php) | [HtmlDeriverTest](../tests/HtmlDeriverTest.php): Alternative/Supplement; [AdvancedDerivationTest](../tests/AdvancedDerivationTest.php): head metadata, SVG dimensions and duplicate IDs; [HtmlBoundaryTest](../tests/HtmlBoundaryTest.php): head resources and stylesheets; [ReadingScenariosTest](../tests/ReadingScenariosTest.php): URL media types and local-file URL policy. |
| Annex A | URL/CSS/fragment validation, escaping, entity/DTD rejection, cumulative traversal/output/asset budgets, graph-cycle checks and checks before expansion. | [SafetyFilter](../src/Html/SafetyFilter.php), [Serializer](../src/Html/Serializer.php), [ReadingBudget](../src/Reading/ReadingBudget.php) | [HtmlBoundaryTest](../tests/HtmlBoundaryTest.php): fragments, CSS obfuscation, entity expansion, cycles and raw closing tags; [ReadingScenariosTest](../tests/ReadingScenariosTest.php): cyclic destinations, shared trees, UTF-16 XMP and Unicode snapshot/expansion budgets. |
| Annex C | `PdfToHtml` requests an injected authentication provider for encrypted input and requires authenticated permissions; derivation emits the permission context. Decryption and viewer enforcement belong to the supplied implementation/application. | [PdfToHtml](../src/PdfToHtml.php), [AuthenticationProvider](../src/AuthenticationProvider.php), [AuthenticatedPermissions](../src/Model/AuthenticatedPermissions.php) | [HtmlDeriverTest](../tests/HtmlDeriverTest.php): `testEncryptedPermissionContextAndSessionReuse`; the provider interface is an integration boundary, not a built-in cryptographic backend. |
| Annex D | Reading order, language, alternatives, form labels and table relationships are carried by the preceding mechanisms. Semantic authoring and assistive-technology review remain necessary. | [StructureProperties](../src/Model/StructureProperties.php), [WidgetDeriver](../src/Forms/WidgetDeriver.php), [AttributeDeriver](../src/Attributes/AttributeDeriver.php) | [AdvancedDerivationTest](../tests/AdvancedDerivationTest.php): form labels/navigation; [independent oracle](../tools/validate_derivation.py): language, alternatives, IDs and links. No accessibility certification is inferred. |

For the numbered body, the physical PDF page equals the printed page plus six.
The source inventory records physical ranges.

MathML filtering admits **192 element names** from the W3C MathML 3 RelaxNG
modules, their 129 schema attribute names and explicitly supported global/Core
additions. This is a vocabulary/security filter, not validation against every
MathML grammar constraint. Presentation, Content and elementary MathML are
preserved where accepted; Content MathML and `mstack` are not obsolete, but native
browser rendering is not promised. Vocabulary outside the supported Core subset
reports `extended-mathml-vocabulary`. No computer algebra system or rendering
polyfill is supplied. Namespace and HTML-integration tests in
[HtmlBoundaryTest](../tests/HtmlBoundaryTest.php) also cover forged MathML names,
foreign XML and embedded HTML. SVG/OpenMath foreign XML is outside this profile.

## File-ingestion boundaries

- The native reader accepts PDF 1.0–2.0 syntax and PDF 2.0 UTF-8 strings. It still
  rejects encrypted input, hybrid xrefs, unsupported filters and damaged syntax.
  An `AuthenticationProvider` supplies the external authenticated/decrypted model;
  this package does not include native cryptographic authentication.
- The input needs a usable structure tree. No OCR, inferred tagging or geometric
  reading-order reconstruction is performed. MCIDs, parents, cycles, page
  membership and namespace identities are checked explicitly. Inverse ParentTree
  associations are not checked.
- Text uses embedded ToUnicode codespaces, bfchar/bfrange and embedded-stream
  UseCMap inheritance, or known simple-font encoding vectors/Differences with
  unambiguous glyph mappings. Named external CMaps and composite fonts without
  a usable ToUnicode collection are not implicitly resolved. Unsupported text
  becomes an explicit graphic/diagnostic node; malformed mappings are rejected.
  Recognizing an encoding vector does not provide complete fallback glyph
  mappings for every font, including Symbol, ZapfDingbats and MacExpert.
- Inline `BI…ID…EI` images are rejected explicitly rather than scanned
  heuristically. Image extraction accepts supported DeviceGray/DeviceRGB JPEG or
  decoded eight-bit samples without Mask/SMask/Decode transformations. Other
  codecs, masks, colour spaces and depths are not decoded natively;
  `unsupported-image` nodes with retained logical dimensions derive to placeholders.
  Extending the native reader or supplying a different source adapter is necessary
  to recover their pixels.
- Affine transforms determine logical edge lengths, not sample dimensions or a
  complete CSS reproduction of PDF positioning/clipping. Degenerate/non-finite
  transforms and ambiguous reused Form/image contexts fail explicitly or produce
  an unsupported-image node. `ContentRenderer`/`AppearanceRenderer` are explicit
  rendering ports, but they do not recover geometry or state discarded by the
  native file adapter. Full appearance/group rendering from files is not supplied.

## Safe defaults and enabled profiles

Section 4.6.1 and annex A, physical pages 62 and 68, explicitly permit security
restrictions on associated content. The following opt-in policies are deliberate
security choices. They do not resolve the technical gaps listed above.

The default `DerivationPolicy` disables `allowActiveContent` and
`allowRemoteResources`, keeps `preferVisualFigures=false` and produces semantic
figure content. Native PHP derivation never executes scripts or fetches URLs.
Supported, sanitized embedded HTML/MathML and raster assets can still be derived.

Enabling `allowActiveContent` deliberately exports trusted JavaScript/runtime and
allows restricted imported CSS/SVG; it is not a browser sandbox or proof that
source scripts are safe. `allowRemoteResources` separately permits output resource
URLs, which a receiving browser may fetch. It does not make the PHP library fetch
them. Neither setting implements the full Acrobat/ISO 21757-1 JavaScript API.

`ScriptAssetEmitter` externalizes enabled script source as
`pdf-script-<sha256>.js`, retaining its exact JavaScript bytes without HTML
closing-script ambiguities. Applications must write every returned asset beside
the HTML, or consistently remap URLs. This is packaging, not script sanitization.
[HtmlBoundaryTest](../tests/HtmlBoundaryTest.php) exercises closing-script payloads;
the default safe profile emits no script runtime.

`preferVisualFigures=true` requests rendering through the supplied engine;
it neither installs nor launches one. Readonly controls without native HTML
readonly behavior report the required application/runtime enforcement. Form
submission, trust and permission enforcement remain application responsibilities.

Accessible HTML still requires review of authoring, reading order, text
alternatives, forms and actual assistive-technology behavior. No blanket ISO,
WTPDF, PDF/UA or WCAG certification is claimed.

## Reproducible evidence

CI runs PHP 8.3–8.5, PHPUnit, static analysis and formatting. It also runs:

1. [validate_reference.py](../tools/validate_reference.py): original filename,
   900894 bytes, SHA-256, CC-BY-4.0 identity and 74 pages via an independent reader.
2. [build_reading_fixture.py --check](../tools/build_reading_fixture.py): recreate
   the original MIT PDF 2.0 fixture using pinned pypdf 6.20.1 and compare its bytes
   and manifest. No PHP writer creates this input.
3. [validate_derivation.py](../tools/validate_derivation.py): independently inspect
   the PDF with pypdf, run the PHP conversion and parse the real result with
   html5lib's HTML5 parser. Check Unicode, title/language, forms, links, unique IDs,
   alternatives and asset hashes, plus six synthetic semantic transformations.
   It also checks PDF 2.0 simple-font metrics, indirect descriptors and the
   AcroForm default-appearance font resources independently of text extraction.
4. [runtime.cjs](../tests/Script/runtime.cjs): execute the opt-in bridge in a bounded
   Node harness, exercising common field operations without a browser or network.

The initial 0.1.0 candidate passes **192 tests / 592 assertions on each PHP
version**, PHPStan level 8, PSR-12 checks across 78 PHP files and Composer's strict,
platform and advisory checks. These counts identify the reviewed candidate;
the executable suite remains the source of truth for subsequent changes.

The input has SHA-256
`53b69e6ab8bf438c5fd896990ce26e1fbd0eb8a8a475c4a1bbd9e8e90fab23c0`.
Its one-pixel image is painted at 20 PDF points: reading preserves 20 × 20 points
and derivation produces rounded **27 × 27 CSS pixels**, rather than treating the
sample grid as its physical size. Matrix tests additionally cover q/Q, nested
Forms, rotation, shear and UserUnit.

Central documentation presents the input PDF, every rendered page, executable PHP,
actual HTML/JSON and exact released-source provenance. HTML5 parsing and these
tests are evidence for the exercised cases, not a universal HTML/CSS/ARIA validator
or automatic accessibility certification.

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
