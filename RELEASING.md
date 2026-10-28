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
