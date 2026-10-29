# Security of derived HTML

[English](security.md) · [Français](fr/security.md)

## Distinguish PDF and browser trust boundaries

A PDF can contain HTML/CSS, associated files, unsafe URLs, scripts and form
actions. Successful parsing does not make those values safe in a browser.
Preserve cumulative reader/derivation budgets and inspect diagnostics before
publication. Do not serve source-associated bytes as executable content just
because their declared media type is HTML or JavaScript.

## Choose explicit execution and resource policies

The default path does not execute scripts, perform network requests or read
arbitrary local filenames. URLs are values, not requests. Resolve relative assets
through application-chosen storage; never concatenate an untrusted source name
with an output directory. Encrypted input needs actual authentication and
extraction permissions rather than a fabricated permission flag.

A deliberate script-export option is separate from execution or an Acrobat
runtime. Browser events and form submission need application review. The safe
profile intentionally restricts the reference's active-content mechanisms.

`FormActionPolicy` grants library-owned reset/submission separately from PDF
ECMAScript. Both are disabled by default. Enabling reset neither exports PDF
scripts nor enables submission. PHP/Node cases check `/DV`, selection/exclusion
and NoExport. Review submission destinations before enabling that capability.

The default `PdfToHtml` source adapter uses the deriver's policy. Blocked associated
files are not decoded and do not suppress original content. Source/output policies
must also match when an application wires its own stages.

An explicitly configured reader can perform native Standard password
authentication and provide actual effective user/owner permissions. Wrong
credentials fail before adaptation. Unsupported security handlers and source
MAC mechanisms remain reader errors. Reader 0.4 verifies source PDF
MACs before content interpretation, and `RequirePdfMac` rejects unprotected input
on both native and provider paths. The provider is a trusted application
capability returning an authenticated `ReadDocument` for identical source bytes;
the adapter cannot drop or elevate its effective permissions. No CMS signature
mathematics, PKI trust or authentication of generated HTML is implied. See
[authentication](authentication.md) and [SourceIntegrityTest](../tests/SourceIntegrityTest.php).

Only the reader's typed `EncryptedPdfException` for verified `/Encrypt` triggers
the separate authentication port. Unsupported filters or adaptation errors do not trigger it by
containing an encryption-like word. A provider must return verified permissions,
not just a guessed mask.
[AuthenticationBoundaryTest](../tests/AuthenticationBoundaryTest.php) checks
that absent/null encryption entries, interpretation errors and provider failures
are not reclassified into an authentication request, and rejects unverified permissions.

Enabled scripts are returned as external JavaScript assets by `ScriptAssetEmitter`.
This avoids HTML raw-text parsing ambiguities without changing the script grammar;
it does not make the code safe. Persisting or serving those assets is an explicit
application responsibility, with its own origin and content-security policy.

Generated library form runtimes are externalized too. They contain library code,
not embedded PDF script bodies when PDF scripting is disabled. They still need
to be served deliberately under the application's browser policy.

An explicitly supplied signature provider is a different capability. Its result
must bind to the exact request/source/field, and its explanation is escaped as
text. The supplied integrity adapter checks the physical Contents gap and complete
signed revision before calling a chosen core verifier. An `integrity-verified`
state does not establish PKI trust, revocation, timestamp validity, permission for
later PDF revisions or authenticity of the derived HTML. The browser popover is
an explanation, not a trust root. See [signatures](signatures.md).

Embedded CSS has its own opt-in `allowEmbeddedStylesheets` capability. It does
not enable source scripts or network access. `CssSafetyFilter` rejects imports,
resource functions (including string-valued `image()`/`image-set()`), escapes,
expressions and unknown at-rules, preserves duplicate fallback declarations,
and bounds nested `@supports`/`@media` rules. CSS can still hide or reposition
content: use the same separate-origin or sandboxed preview boundary. The
`mergeCatalogHtmlSupplements` interoperability option changes catalogue file
selection only; it grants no execution capability. See [policies](policies.md).

## Validate before serialization

Escape text and attribute values. Validate element/attribute names, URL schemes,
CSS declarations, identifiers and associated-file media types. Control characters
and mixed-case `javascript:` remain unsafe. CSS can fetch resources or hide content
and needs its own policy. SVG/MathML/imported HTML need structural validation.

MathML filtering distinguishes namespaces and HTML integration points. Unknown
MathML names, namespace spoofing, active attributes and unsupported foreign XML
are rejected or diagnosed, including under the trusted-content policy. Content
MathML preservation does not imply that a browser can render or evaluate it.

`ParentTreeValidator` has explicit permissive/strict modes. Permissive never
disables cycle or cumulative-budget checks. Inline boundaries follow exact codec
rules or an injected provider, not binary delimiter guessing. Graphics export
bounds resources/output bytes, isolates selected paints and strips catalog
execution capabilities. Running the application's rendering engine is an
explicit, separate integration decision.
The export also checks cumulative instructions and actual resource-graph depth,
including indirect dictionaries and Form caller chains. External stream `/F`
references are refused rather than resolved as local filenames.

Preview untrusted conversion in a separate origin or sandboxed frame. This browser
boundary complements validation. Do not combine `allow-scripts` and
`allow-same-origin` for untrusted output.

## Verify and report

The executable suite checks semantic output, hostile values and input bounds.
Such checks provide evidence for supported cases, not proof that software can
never have a vulnerability. Report issues privately under the
[security policy](../SECURITY.md).
