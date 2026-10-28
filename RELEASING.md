# Releasing pdftoolkit/pdf-to-html

[English](RELEASING.md) · [Français](RELEASING.fr.md)

Keep coherent atomic commits. The first public version is 0.1.0; before 1.0 a
feature increments the minor version and compatible corrections/documentation
increment the patch version.

1. Publish changed dependencies first. Runtime constraints must remain stable;
   never release requirements on development branches or unpublished commits.
   Update the lock from published versions.
2. Update both changelogs and document input, policies and limitations.
3. Run Composer validation, locked installation, platform checks, audit, tests,
   PHPStan, formatting, reference fingerprints and independent HTML/PDF checks.
   Run `tools/validate_mathml.cjs` with Chromium and Firefox; retain browser
   versions and the concrete layout results separately from PHP/HTML parsing.
   Run `validate_attribute_conformance.cjs`, `validate_widgets.cjs` and
   `validate_signature_popovers.cjs` on actual PHP exports in both browsers.
   Run the independent `validate_appearances.py` and `validate_signatures.py`
   oracles, plus example 05 with the committed test signer in DER and PEM form.
   Preserve reports/screenshots and exact source/tool versions; mathematical
   signature integrity must not be reported as full PKI trust.
   Rebuild the sample/codec fixtures with their `--check` builders and run
   `validate_inline_fax.py`, `validate_sample_images.py` and `validate_codec_images.py`
   on all three PHP versions. Run `validate_sample_images.cjs` and
   `validate_codec_images.cjs` in Chromium and Firefox on those exact exports.
   Keep exact samples/ICC transport, explicit fallbacks and independent colour
   witnesses distinct from differences between browser colour engines.
   Require PHP 8.3, 8.4 and 8.5 CI success for the exact candidate SHA.
4. Verify the new `vX.Y.Z` tag is absent locally and remotely. Create an annotated
   tag at that validated SHA, push it and publish a GitHub release with the
   prepared notes. Never move a published tag.
5. Verify remote peeled SHA, release metadata and Packagist version/reference.
   Install the exact stable version in a fresh consumer using default Composer
   repositories without VCS/path overrides. Run the documented example.
6. Update `PDFToolkit/pdftoolkit.github.io/resources/sources.json` to that release
   and exact commit. Build bilingual guides/API and real HTML/PDF examples,
   verify deployment and publish a separate documentation version.

The core must not depend on this optional module. Do not release unchanged
packages to synchronize version numbers. Original source PDFs remain unchanged
under `resources/reference-documents/` and outside Composer archives.
