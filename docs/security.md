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

Enabled scripts are returned as external JavaScript assets by `ScriptAssetEmitter`.
This avoids HTML raw-text parsing ambiguities without changing the script grammar;
it does not make the code safe. Persisting or serving those assets is an explicit
application responsibility, with its own origin and content-security policy.

## Validate before serialization

Escape text and attribute values. Validate element/attribute names, URL schemes,
CSS declarations, identifiers and associated-file media types. Control characters
and mixed-case `javascript:` remain unsafe. CSS can fetch resources or hide content
and needs its own policy. SVG/MathML/imported HTML need structural validation.

MathML filtering distinguishes namespaces and HTML integration points. Unknown
MathML names, namespace spoofing, active attributes and unsupported foreign XML
are rejected or diagnosed, including under the trusted-content policy. Content
MathML preservation does not imply that a browser can render or evaluate it.

Preview untrusted conversion in a separate origin or sandboxed frame. This browser
boundary complements validation. Do not combine `allow-scripts` and
`allow-same-origin` for untrusted output.

## Verify and report

The executable suite checks semantic output, hostile values and input bounds.
Such checks provide evidence for supported cases, not proof that software can
never have a vulnerability. Report issues privately under the
[security policy](../SECURITY.md).
