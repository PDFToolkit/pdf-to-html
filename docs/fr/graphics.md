# Conserver les graphiques PDF et rendre les figures complètes

[English](../graphics.md) · [Français](graphics.md)

La section 4.4 distingue réutilisation sémantique et fidélité visuelle. Lorsque
l'apparence d'un graphique ou d'une infographie compte, son contenu doit être
traité en groupe. Rendre ses tracés séparément de son texte peut changer le résultat.

## Conserver le programme source

`ReadPdfSource` attache des objets immuables `GraphicProgram` aux contenus.
Chaque objet conserve les instructions typées partagées de son conteneur, ses
ressources, le résolveur source, sa boîte physique et la plage inclusive des
instructions sélectionnées. Les programmes Form appelés conservent aussi l'instruction
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

Un Form autonome atteint par une référence d'objet reste un XObject Form dans
le PDF exporté. Ses `BBox`, groupe de transparence `Group` et
appartenance au contenu optionnel `OC` restent portés par cet objet. Sa vraie
frontière de clipping est ainsi conservée même avec une matrice de cisaillement ;
un rectangle de page aligné sur les axes exposerait des peintures hors du Form.
L'enveloppe applique la transformation composée page/Form et appelle une fois
le Form. Le dictionnaire cloné omet `Matrix`, déjà appliquée par cette enveloppe.

```sh
php examples/02-export-figure.php tests/Fixtures/tagged.pdf figure.pdf
```

La CLI choisit ses chemins d'entrée et de sortie. L'exporteur de la bibliothèque
retourne un `RenderingDocument`, sans écriture de fichier ni lancement de
programme. Sa fenêtre utilise des points physiques ; rotation et `UserUnit`
s'appliquent une seule fois. Une figure peut inclure des Form imbriqués sous
une même racine source ; une image ne combine pas silencieusement des racines
ou pages indépendantes.

[`PageTransform`](../../src/Reading/PageTransform.php) est l'objet readonly commun
à cette conversion. Il reçoit un `ReadPage` ; sa propriété publique `matrix`
transforme l'espace utilisateur de page par défaut en points PDF physiques.
UserUnit vaut 1 si absent ou null, dont un null indirect ; les autres valeurs
doivent être positives et finies. La matrice du Form est composée une seule fois
avec la transformation de page ; l'export ne l'applique pas à nouveau lors de
l'appel du XObject racine.

Pour les programmes de page, le viewport par défaut est la CropBox physique de
la source ; les programmes Form autonomes utilisent les limites transformées du
Form. Une `BBox` de structure peut définir celui de la figure, ou l'application peut fournir
un `GraphicViewport` explicite dans `ContentGroup`. Ces limites en coordonnées
source sont transformées une seule fois par la matrice de page, y compris pour
un Form autonome : les viewports explicites utilisent les coordonnées de page,
pas les coordonnées locales du Form. L'export ne
découvre pas un rectangle serré à partir des pixels peints ; choisir un viewport
explicite pour un cadrage plus petit.
La lecture native conserve le contexte de page source ; l'application qui
construit un modèle Form autonome fournit aussi ce contexte pour demander
un viewport explicite en coordonnées de page, sinon l'export échoue explicitement.

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
de page et la visibilité du contenu optionnel, dont les références d'objet Form
autonomes, leur clipping cisaillé et les viewports explicites en coordonnées de
page avec rotation/UserUnit ; la peinture Artifact imbriquée
est volontairement absente. La version PDF 1.7 source est également vérifiée.
[GraphicExporterContextTest](../../tests/Rendering/GraphicExporterContextTest.php)
ajoute les refus d'instructions cumulatives et de profondeur de ressources
indirectes. Ces tests vérifient aussi qu'un UserUnit null direct/indirect produit
le même export que son absence. Ces oracles prouvent les cas exercés, sans promettre la fidélité
de pages PDF arbitraires.

Les alias des images inline suivent ISO 32000-2 EC3 issue3 (page physique 283) :
si un nom abrégé et son nom complet coexistent, la valeur abrégée prévaut quel que
soit l'ordre, y compris `L`/`Length`. Chaque valeur source reste soumise aux contrôles
de syntaxe, de référence directe et de budget ; les noms décodés dupliqués restent
invalides. Seuls les paramètres effectifs de filtre et d'espace colorimétrique sont
interprétés. La fin des données reste vérifiée par le codec ou les dimensions des
échantillons, et les opérateurs de contenu suivants sont conservés.

Les sections de compatibilité `BX`/`EX` s'équilibrent indépendamment de `q`/`Q`,
`BT`/`ET` et des paires de contenu marqué, selon EC3 issue302 (pages physiques 126
et 736). Une section ouverte, une fermeture sans ouverture ou des opérandes sur
ces opérateurs sans paramètre provoquent une erreur. L'imbrication consomme le
budget de profondeur existant. Les instructions prises en charge conservent leur
sens ; les instructions inconnues sont ignorées dans la section, tout en restant
présentes dans le programme source. Hors section, elles conservent le substitut
explicite d'opérateur non pris en charge.

EC3 issue365 (page physique 38) réserve les délimiteurs accolades aux fonctions de calcul de type 4. Les noms d’opérateurs de contenu conservent les accolades ; les noms d’opérandes utilisent la même syntaxe PDF générale corrigée dans Reader 0.6.
