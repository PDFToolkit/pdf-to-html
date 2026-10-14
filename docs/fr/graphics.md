# Conserver les graphiques PDF et rendre les figures complètes

[English](../graphics.md) · [Français](graphics.md)

La section 4.4 distingue réutilisation sémantique et fidélité visuelle. Lorsque
l'apparence d'un graphique ou d'une infographie compte, son contenu doit être
traité en groupe. Rendre ses tracés séparément de son texte peut changer le résultat.

## Conserver le programme source

`ReadPdfSource` attache des objets immuables `GraphicProgram` aux contenus.
Chaque objet conserve les instructions typées partagées de son conteneur, ses
ressources, le résolveur source, sa boîte physique et la plage inclusive des
instructions sélectionnées. Les programmes Form conservent aussi l'instruction
`Do` appelante et leur stream original. Couleurs, tracés, clipping,
transformations, dégradés et état des polices restent accessibles au moteur de rendu.

`GraphicPdfExporter` construit un PDF isolé en mémoire. Il rejoue l'état
graphique et conserve les déplacements du texte en rendant invisible le texte
non sélectionné. Le mode hérité de rendu/clipping du texte est conservé, dont
les appels Form. Artefacts imbriqués et peintures non sélectionnées sont omis ;
les streams Form partiellement sélectionnés sont substitués. Groupes de
transparence de page, appartenance/visibilité par défaut du contenu optionnel
restent disponibles. L'export garde la version PDF effective de la source,
sans la forcer à 2.0. Les ressources d'image et de police
compressées sont importées sans décodage ni recompression. L'export n'inclut
ni AcroForm, ni actions du document, ni ECMAScript PDF. Les streams dans des
fichiers externes sont refusés. Les budgets d'objets, instructions et octets sont
cumulatifs pour l'export ; la profondeur réelle des ressources/appelants est bornée.

```sh
php examples/02-export-figure.php tests/Fixtures/tagged.pdf figure.pdf
```

La CLI choisit ses chemins d'entrée et de sortie. L'exporteur de la bibliothèque
retourne un `RenderingDocument`, sans écriture de fichier ni lancement de
programme. Sa fenêtre utilise des points physiques ; rotation et `UserUnit`
s'appliquent une seule fois. Une figure peut utiliser plusieurs conteneurs
sources, mais une image ne combine pas silencieusement des pages indépendantes.

Le viewport par défaut est la CropBox physique de la page source. Une `BBox`
de structure peut définir celui de la figure, ou l'application peut fournir
un `GraphicViewport` explicite dans `ContentGroup`. Ces limites en coordonnées
source sont transformées une seule fois par la matrice de page. L'export ne
découvre pas un rectangle serré à partir des pixels peints ; choisir un viewport
explicite pour un cadrage plus petit.

## Fournir le moteur sous forme d'objet

Implémentez `GraphicsRasterizer::rasterize(RenderingDocument): ImageContent`
avec le moteur choisi par votre application, puis injectez-le :

```php
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\PdfToHtml;
use PDF\Html\Rendering\RasterContentRenderer;

// $rasterizer est votre implémentation explicite de GraphicsRasterizer.
$renderer = new RasterContentRenderer($rasterizer);
$converter = new PdfToHtml(deriver: new HtmlDeriver(
    policy: new DerivationPolicy(preferVisualFigures: true),
    renderer: $renderer,
));
$result = $converter->convert($fileBytes, 'chart.pdf');
```

Le rasteriseur retourne des octets PNG, JPEG ou GIF portables. Le renderer
conserve les dimensions logiques indépendamment de la résolution du raster.
Choisissez et isolez le moteur selon la politique d'entrée de votre application.
L'installation du package ne démarre aucun moteur implicitement.

Le profil visuel échoue explicitement si le renderer manque ou refuse le groupe.
La réutilisation sémantique peut omettre des tracés et dégradés non pertinents,
comme le permettent les sections 4.4.1 et 4.4.5 ; les diagnostics les identifient.
Une image non convertible reçoit un remplacement à sa taille logique d'affichage.

## Preuves de rendu indépendantes

`tools/validate_graphics.py` analyse les exports avec pypdf et les rend avec
Poppler. Les contrôles de pixels vérifient le clipping, les couleurs héritées des
Form, l'exclusion d'autres MCID du Form, l'avancement du texte invisible,
la rotation et `UserUnit`. La syntaxe PDF de ces cas est rédigée indépendamment du writer.

[validate_graphics_context.py](../../tools/validate_graphics_context.py) analyse
et rend indépendamment les cas de contexte originaux/exportés. Il compare les
buffers complets de pixels pour le clipping du texte Form hérité, la transparence
de page et la visibilité du contenu optionnel ; la peinture Artifact imbriquée
est volontairement absente. La version PDF 1.7 source est également vérifiée.
[GraphicExporterContextTest](../../tests/Rendering/GraphicExporterContextTest.php)
ajoute les refus d'instructions cumulatives et de profondeur de ressources
indirectes. Ces oracles prouvent les cas exercés, sans promettre la fidélité
de pages PDF arbitraires.
