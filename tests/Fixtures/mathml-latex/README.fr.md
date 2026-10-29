# Cas d’interopérabilité LuaLaTeX

[English](README.md) · [Français](README.fr.md)

Ces documents originaux PDFToolkit ont été compilés par **LuaHBTeX 1.24.0
(TeX Live 2026)**. Il s’agit de vraies sorties LaTeX, sans réécriture ultérieure
des objets PDF. Les sources `.tex` utilisent l’interface
[`math/setup` documentée par le projet LaTeX](https://latex3.github.io/tagging-project/documentation/usage-instructions#mathml-structure-element-tagging).

| Fichier | Périmètre |
| --- | --- |
| `mathml-se.pdf` | Éléments de structure MathML seuls : exposant/fraction dans le texte, équation centrée et matrice deux par deux. Aucun fichier MathML associé. |
| `mathml-se-af.pdf` | Les mêmes structures mathématiques avec trois fichiers XML MathML associés selon `AFRelationship=Supplement`. |
| `latex-layout.pdf` | Liste `itemize` imbriquée et deux équations `align` numérotées, avec les fragments HTML de feuilles de style associés par LaTeX. |

`LatexMathMlInteropTest` vérifie les espaces de noms PDF réels, les empreintes,
les caractères mathématiques Unicode, les modes inline/display, la matrice et
l’absence de formules dupliquées. Dans le cas combiné, les identifiants de
structure originaux sont conservés en HTML, tandis que le XML associé n’a pas
d’identifiants : cela rend observable la priorité des SE sur les AF.
La démo publique utilise `mathml-se.pdf` ; ses tests navigateur mesurent la
géométrie des exposants, fractions et matrices, et comparent la sortie
WebAssembly à celle de PHP natif.

## Régénération et provenance

Utiliser une installation locale explicite de LuaLaTeX TeX Live 2026 avec
`unicode-math`, `tagpdf`, `latex-lab`, `luamml`, `fontspec`, `amsmath` et les
fontes Latin Modern :

```sh
python3 tools/generate_latex_mathml.py --lualatex /chemin/absolu/vers/lualatex
```

Le générateur n’installe aucun logiciel et n’accède pas au réseau. Il exécute
deux passes sans shell escape dans chacun de deux dossiers temporaires propres
et indépendants, puis refuse toute différence entre les PDF. Les sources
fixent la graine aléatoire et les identifiants de fin du PDF. Le générateur fixe
`SOURCE_DATE_EPOCH`, `FORCE_SOURCE_DATE` et UTC. `provenance.json` conserve les
SHA-256 des sources/PDF, la version du compilateur, les empreintes des principaux
paquets, leurs lignes de version réelles et le résultat de reproductibilité.
L’identité des octets a été établie avec ce compilateur et ces paquets précis ;
une installation TeX plus récente peut produire des octets PDF différents mais
valides et doit être relue avant de mettre à jour les fichiers conservés.
Les tests habituels et le build de la démo utilisent les PDF conservés et n’ont
pas besoin d’une installation TeX.

Les textes et sources TeX originaux utilisent la licence MIT du dépôt. Les
sous-ensembles des fontes Latin Modern / Latin Modern Math incorporées sont
couverts par la [licence GUST Font](https://www.gust.org.pl/projects/e-foundry/licenses/GUST-FONT-LICENSE.txt).
Ces exemples ciblés ne constituent ni un corpus LaTeX complet, ni une
certification d’accessibilité, ni une garantie pour toute expression mathématique.
