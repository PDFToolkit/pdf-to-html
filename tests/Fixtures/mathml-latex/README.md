# LuaLaTeX interoperability fixtures

[English](README.md) · [Français](README.fr.md)

These original PDFToolkit documents were compiled by **LuaHBTeX 1.24.0 (TeX Live
2026)**. They are real LaTeX output, without a post-processing rewrite of their
PDF objects. The `.tex` sources use the LaTeX project's documented
[`math/setup` interface](https://latex3.github.io/tagging-project/documentation/usage-instructions#mathml-structure-element-tagging).

| Fixture | Scope |
| --- | --- |
| `mathml-se.pdf` | MathML structure elements only: an inline exponent/fraction, a displayed equation and a two-by-two matrix. No MathML associated file is present. |
| `mathml-se-af.pdf` | The same mathematical structures plus three MathML XML associated files with `AFRelationship=Supplement`. |
| `latex-layout.pdf` | A nested `itemize` list and two numbered `align` equations, with LaTeX's associated HTML stylesheet fragments. |

`LatexMathMlInteropTest` verifies actual PDF namespaces, source hashes, Unicode
math tokens, inline/display modes, matrix structure and the absence of duplicate
math. In the combined fixture, original structure IDs survive in HTML while the
associated XML has no IDs: this makes the selection of SE over AF observable.
The public demo uses `mathml-se.pdf`; its browser tests measure exponent,
fraction and matrix geometry and compare WebAssembly output with native PHP.

## Regeneration and provenance

Use an explicitly installed local TeX Live 2026 LuaLaTeX with `unicode-math`,
`tagpdf`, `latex-lab`, `luamml`, `fontspec`, `amsmath` and Latin Modern fonts:

```sh
python3 tools/generate_latex_mathml.py --lualatex /absolute/path/to/lualatex
```

The generator does not install software or access the network. It runs two
passes without shell escape, in each of two independent clean temporary
directories, and rejects different PDF bytes. The sources fix the random seed
and PDF trailer identifiers. The generator fixes `SOURCE_DATE_EPOCH`,
`FORCE_SOURCE_DATE` and UTC. `provenance.json` retains source/PDF SHA-256 hashes,
compiler version, key package hashes, actual package version lines and the
reproducibility result. Byte identity was established with that recorded
compiler and package set; a newer TeX installation may generate different
valid PDF bytes and must be reviewed before updating the retained fixtures.
Ordinary tests and demo builds consume retained PDFs and need no TeX installation.

The original prose and TeX sources use the repository's MIT licence. Embedded
Latin Modern / Latin Modern Math font subsets are covered by the
[GUST Font License](https://www.gust.org.pl/projects/e-foundry/licenses/GUST-FONT-LICENSE.txt).
These focused fixtures are not a complete LaTeX corpus, an accessibility
certification or an assertion that every mathematical expression is supported.
