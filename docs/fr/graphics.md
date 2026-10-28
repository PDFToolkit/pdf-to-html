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

## Échantillons d’image natifs et cadrage fax

Pour les images inline et les XObjects image, le chemin natif conserve les échantillons `DeviceGray` à 1, 2, 4, 8 ou 16 bits et `DeviceRGB` à 8 ou 16 bits dans un PNG. Les lignes compactées, largeurs impaires et échantillons 16 bits en ordre grand-boutiste restent exacts ; aucune quantification, transformation de couleur ou alpha inventé n’est appliqué. Les buffers PNG de lignes, compression, assemblage et ressources sont comptabilisés avant allocation. Les dimensions HTML logiques restent indépendantes des dimensions en pixels.

Reader décode les filtres PDF disponibles, dont CCITT MH/MR/MMR natif et l’extension non compressée T.4/T.6. `InlineFaxBoundaryReader` adapte `CcittFaxDecoder::decodePrefix()` de Reader aux budgets cumulatifs de nœuds, instructions et octets du document HTML. Il ne maintient plus de seconde table de codes ni d’automate de lignes fax. Reader retourne la longueur consommée et les échantillons ; HTML valide le délimiteur `EI` suivant. Des octets encodés contenant `EI` ne terminent pas l’image. Un fax sans marqueur de fin de bloc ni nombre de lignes positif exige toujours un fournisseur de frontière explicite ; un encodage connu malformé produit une erreur, jamais une frontière devinée. Un fax enveloppé dans un encodage ASCII utilise la frontière du codec extérieur et la chaîne de filtres Reader pour ses échantillons.

Le chemin PNG des échantillons hors codecs exige le décodage par défaut et l’absence de `Mask` ou `SMask`. `Indexed`, CMYK, autres espaces de couleur, masques et `Decode` explicite utilisent toujours le programme graphique conservé et le renderer explicite ou le substitut dimensionné. Leurs propriétés de couleur ou de transparence ne sont donc pas abandonnées silencieusement. La section 4.4.3 de la référence Deriving HTML conservée (pages physiques 53–54) autorise ce substitut explicite si la conversion est indisponible ; les nouveaux chemins natifs étendent les capacités sans réinterpréter cette permission.

[SampleImageReadingTest](../../tests/SampleImageReadingTest.php) et
[validate_sample_images.py](../../tools/validate_sample_images.py) couvrent 14
chemins natifs inline/XObject et trois replis valides Decode/masque doux/clé de
couleur. pypdf établit la liaison des streams source ; zlib détermine indépendamment
les frontières inline Flate binaires, dont les données courtes rejetées par son
heuristique EI générique. CRC, en-têtes et octets d’échantillons PNG compactés
sont vérifiés exactement. Pillow décode indépendamment le PNG et Poppler rend le
PDF original. Le rendu compare des canaux d’affichage 8 bits (octet haut pour
les aperçus 16 bits) ; les comparaisons d’octets prouvent séparément la
conservation des 16 bits. Le harness navigateur contrôle le décodage réel et les
dimensions logiques dans Chromium et Firefox. L’oracle fax conserve sept cas
encodés indépendamment par libtiff et compare les pixels PNG natifs au rendu du
PDF original par Poppler ; les vecteurs normatifs non compressés ont des tests
séparés de bitmaps explicites, sans revendiquer un décodeur indépendant.

Le chemin des codecs utilise aussi les plans exacts de Reader, conserve les règles ICC/alpha JPX et vérifie ColorTransform avant réemploi JPEG. Voir [images décodées et limites du navigateur](image-codecs.md) pour les règles, replis explicites et preuves indépendantes.

## Réutiliser le moteur pour les apparences de widgets

Lorsqu’un `ContentRenderer` est fourni, la session l’adapte automatiquement en
`ContentAppearanceRenderer` pour les widgets. Un `AppearanceRenderer` explicite
reste prioritaire. Pour configurer directement l’adaptateur :

```php
use PDF\Html\Rendering\ContentAppearanceRenderer;

$deriver = new HtmlDeriver(
    renderer: $renderer,
    appearanceRenderer: new ContentAppearanceRenderer($renderer),
);
```

Le lecteur sélectionne les streams AP N/D/R ou l’état nommé correspondant
exactement à AS. Il ne choisit pas arbitrairement un état si AS manque ou ne
correspond pas. Les limites Matrix/BBox sont ajustées au Rect du widget, puis
composées une seule fois avec rotation et UserUnit de page ; chaque utilisation
conserve son contexte d’appel. Apparence normale et légende restent disponibles ;
les apparences appuyée/survol participent aux interactions CSS natives des widgets.

L’adaptateur rend toute l’apparence en un groupe et vérifie octets PNG/JPEG/GIF
portables, dimensions logiques et budgets de sortie/ressources. L’adaptateur
automatique partage le budget cumulatif de ressources de la session. Il n’effectue
aucune entrée/sortie implicite. Renderer, polices et environnement restent fournis
par l’application ; l’absence de capacité visuelle produit le repli textuel
documenté, pas des pixels inventés.
[validate_appearances.py](../../tools/validate_appearances.py) compare les exports
isolés avec le rendu natif des annotations via pypdf/Poppler indépendants, dont deux
tailles, N/D/R, artefacts imbriqués et cas ne contenant que des artefacts.

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

Les images avec `OC` ou `Alternates` nécessitent le port de rendu explicite (EC3
issue79, pages physiques 279–280). L’extraction directe des pixels RGB/gris ne peut
pas décider quelle image est visible ou sélectionnée pour l’impression. Ces images
conservent leurs dimensions logiques et leur programme source complet dans un
élément `unsupported-image-context` ; l’export isolé garde les ressources d’image
et les `OCProperties` du catalogue. Le repli est un substitut avec diagnostic, sans
revendiquer que les pixels bruts correspondent à l’image visible.
