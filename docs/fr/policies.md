# Politiques de dérivation et capacités de l'application

[English](../policies.md) · [Français](policies.md)

La référence permet des restrictions de sécurité sur le contenu associé dans
la section 4.6.1 et l'annexe A, pages physiques 62 et 68. Un PDF ne peut pas
activer ses propres autorisations. L'application fournit des objets explicites
pour ces décisions.

## Utiliser une politique commune à la lecture et à la sortie

```php
use PDF\Html\DerivationPolicy;
use PDF\Html\HtmlDeriver;
use PDF\Html\PdfToHtml;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Reading\StructureValidationMode;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

$policy = new DerivationPolicy();
$deriver = new HtmlDeriver($policy);
$converter = new PdfToHtml(deriver: $deriver);
$result = $converter->convert(new FileBytes($pdfBytes), 'document.pdf');
```

`PdfToHtml` construit son adaptateur source par défaut avec la politique et les
limites du dériveur. `AssociatedFileSelection` décide ainsi si une Alternative
ou un Supplement s'applique avant de lire le contenu PDF remplacé. Un contenu
associé bloqué ne supprime pas le contenu PDF original.

L'application qui choisit un adaptateur distinct fournit le même profil et les
mêmes limites. Des instances immuables équivalentes sont acceptées ; des valeurs
incompatibles sont refusées dès la construction de `PdfToHtml` :

```php
$source = new ReadPdfSource(
    policy: $policy,
    derivationLimits: $deriver->limits,
    structureValidation: StructureValidationMode::Strict,
);
$converter = new PdfToHtml(source: $source, deriver: $deriver);
```

Le mode strict refuse les associations inverses ParentTree incohérentes. Le mode
permissif les décrit dans `sourceDiagnostics`, repris dans les diagnostics de
dérivation. Les cycles et dépassements de budgets cumulatifs restent des erreurs
dans les deux modes. Aucun mode ne constitue une certification complète WTPDF,
PDF/UA ou d'accessibilité.

`StandardPasswordAuthentication` appartient au lecteur configuré explicitement.
Il fournit les permissions effectives utilisateur/propriétaire vérifiées à
`ReadPdfSource` ; un mot de passe incorrect est une erreur, sans nouvelle
tentative par un autre port. Voir l’[authentification](authentication.md).

`PdfToHtml` ne déclenche le port d’authentification distinct que lorsque le lecteur produit
l'`EncryptedPdfException` typée pour `/Encrypt` vérifié. Les erreurs
d'interprétation suivantes et noms de filtres non acceptés ne sélectionnent
jamais l'authentification par texte. Le `AuthenticationProvider` reçoit la
politique d’intégrité effective et renvoie un `ReadDocument` authentifié pour
les octets fournis exacts, avec permissions vérifiées. Le convertisseur contrôle
le MAC exigé avant adaptation et refuse toute suppression/élévation des droits ;
le viewer destinataire applique ces permissions.
Les entrées `Encrypt` absentes ou nulles, directes/indirectes, ne demandent pas
d'authentification. [AuthenticationBoundaryTest](../../tests/AuthenticationBoundaryTest.php)
contrôle le déclenchement, les erreurs d'adaptateur/fournisseur, les entrées nulles
et le refus de permissions non vérifiées.

## Choisir séparément les comportements du navigateur

| Capacité | Valeur par défaut | Comportement activé |
| --- | --- | --- |
| `allowActiveContent` | false | Exporter l'ECMAScript PDF fiable et les contenus associés actifs acceptés. Cela n'exécute aucun code en PHP et ne rend pas les scripts source sûrs. |
| `allowEmbeddedStylesheets` | false | Conserver le CSS incorporé filtré sans autoriser les scripts PDF, les gestionnaires d’événements ou les ressources distantes. |
| `mergeCatalogHtmlSupplements` | false | Choisir le profil d’interopérabilité LaTeX documenté : traiter tous les Supplements HTML acceptés du catalogue dans l’ordre PDF au lieu du premier seulement. |
| `allowRemoteResources` | false | Produire les URL de ressources distantes autorisées. Le navigateur destinataire peut les récupérer, la bibliothèque PHP ne le fait pas. L'import HTML distant exige aussi l'autorisation de contenu actif. |
| `allowHtmlNamespace` | true | Dériver les types de structure HTML explicites reconnus sous le filtre de sécurité. Le désactiver exige un mapping vers un autre namespace reconnu. |
| `formActions.allowReset` | false | Activer le pont ResetForm propre à la bibliothèque : `/DV`, sélection, noms parents et exclusion. |
| `formActions.allowSubmit` | false | Activer les soumissions HTML SubmitForm acceptées. Les flags d'export non pris en charge sont diagnostiqués, sans changer silencieusement de format. |
| `preferVisualFigures` | false | Demander le rendu visuel de groupe au `ContentRenderer` fourni. Une capacité absente provoque un échec explicite pour ce profil visuel demandé. |
| `includeGraphics` | true | Conserver les graphiques pour le moteur fourni. Sans moteur, des diagnostics identifient les graphiques omis. |

Les actions de formulaire et les scripts PDF incorporés sont indépendants :

```php
$policy = new DerivationPolicy(
    formActions: new \PDF\Html\Forms\FormActionPolicy(allowReset: true),
);
```

Cela active l'implémentation de reset propre à la bibliothèque sans exporter les
scripts PDF du document, des pages ou des widgets. Un `/DV` absent retire la
valeur du champ PDF ; il ne restaure pas le `/V` initial. Un `/Fields` vide
explicitement diffère d'une sélection absente, et un nom parent correspond à
ses descendants à la frontière d'un point.

Le pont de script partage une valeur entre les widgets d'un même nom et lie les
événements d'annotation à l'identifiant HTML final de chaque widget. Les cas
PHP/Node exécutables documentent l'API de champs/événements acceptée ; ce n'est
pas une implémentation complète d'Acrobat/ISO 21757-1.

## Fournir explicitement un moteur de rendu

Les nœuds graphiques natifs conservent des objets `GraphicProgram` : opérandes,
état, ressources, plages d'instructions et appels Form englobants.
`GraphicPdfExporter` produit un PDF isolé d'une page contenant les opérations de
peinture demandées. Clipping et avancement du texte peuvent rester nécessaires
même lorsque la peinture d'un autre contenu est omise. Scripts du document PDF,
AcroForm et actions du catalogue ne sont pas exportés dans ce document de rendu.

L'export conserve le mode hérité de rendu/clipping du texte, exclut les artefacts
imbriqués et garde les groupes de transparence de page et la visibilité du contenu
optionnel. Sa version PDF est celle, effective, de la source, sans passage forcé
en PDF 2.0. Les programmes de page utilisent la CropBox physique par défaut ;
les références d'objet Form autonomes conservent leur XObject Form et les
clipping/Matrix/Group/OC originaux, avec les limites transformées du Form.
Une BBox de structure ou un `GraphicViewport` explicite en coordonnées de page
peut remplacer les limites de sortie. Transformation de page et matrice Form
s'appliquent chacune une seule fois. Aucun cadrage
à partir des pixels peints n'est déduit. Les budgets d'objets, instructions et
octets sont cumulatifs ; la profondeur réelle du parcours est bornée.
`PageTransform(ReadPage)` fournit la matrice physique commune de page. UserUnit
absent ou null direct/indirect vaut 1 ; le viewport explicite reste dans l'espace
utilisateur de page par défaut même si la racine sélectionnée est un Form
transformé atteint par référence d'objet.

`RasterContentRenderer` reçoit un objet `GraphicsRasterizer` fourni par
l'application. Cette capacité reçoit un `RenderingDocument` et renvoie un
`ImageContent` ; elle peut utiliser le moteur choisi par l'application. La
bibliothèque ne choisit pas de binaire, ne lance aucun processus et ne résout
pas de fichier externe.

`AppearanceRenderer` reste une capacité distincte pour les apparences des
widgets. Polices disponibles, installation du moteur, limites d'exécution et
environnement de rendu appartiennent à l'application.
L'[oracle graphique](../../tools/validate_graphics.py) contrôle les exports isolés
avec pypdf et de vrais pixels Poppler ; le moteur reste une frontière explicite
d'intégration en usage normal.
L'[oracle de contexte](../../tools/validate_graphics_context.py) compare les
sorties Poppler originales et exportées pour le clipping du texte hérité,
les artefacts, la transparence et le contenu optionnel.
Le [guide graphique](graphics.md) présente le port concret.

## Interaction des widgets et validation des signatures

Modes de surbrillance et apparences appuyée/survol sélectionnées utilisent le CSS
propre à la bibliothèque, sans autoriser les scripts PDF. Le CSS auteur explicite
reste prioritaire. Un renderer de contenu existant est automatiquement adapté aux
apparences ; un `AppearanceRenderer` fourni séparément le remplace. Les popovers
natifs de signature ne nécessitent pas non plus de script PDF.

La validation des signatures possède son fournisseur explicite dans
`ReadPdfSource`, distinct du déchiffrement/MAC source et de `allowActiveContent`.
Sans fournisseur, un champ signé rapporte `not-validated`.
`SignatureOutputValidation` rapporte seulement l’intégrité avec certificat épinglé ;
confiance/révocation/horodatage et permissions des révisions nécessitent une
politique applicative. Lire les [signatures](signatures.md) avant d’interpréter un
état comme une approbation.

## Conserver toutes les ressources renvoyées

`DerivationResult` contient HTML, CSS, ressources et diagnostics. Les runtimes
de formulaire activés et les scripts PDF fiables sont externalisés par
`ScriptAssetEmitter` ; écrire les ressources JavaScript renvoyées à côté du HTML
ou adapter leurs URL de manière cohérente. Les feuilles de style et images
associées demandent aussi la conservation de leurs URL et noms retournés. Les
noms des ressources passent la validation de la frontière de stockage du package.

Servir ces fichiers, accepter les soumissions, appliquer les permissions PDF
authentifiées et choisir origine/politique de sécurité du contenu appartiennent
à l'application. Lire la [sécurité](security.md) et examiner les diagnostics avant
publication.

## Conserver les styles incorporés sans activer les scripts

```php
$policy = new DerivationPolicy(
    allowEmbeddedStylesheets: true,
    mergeCatalogHtmlSupplements: true,
);
$converter = new PdfToHtml(deriver: new HtmlDeriver($policy));
```

`allowEmbeddedStylesheets` accepte les fichiers associés locaux `text/css` et les
éléments `style` incorporés. Chaque déclaration passe encore par
`CssSafetyFilter` : les variantes comme `text-align:right;
text-align:-moz-right; text-align:-webkit-right` restent dans leur ordre source.
Les blocs `@supports` et `@media` imbriqués sont conservés dans la grammaire
acceptée et les budgets cumulatifs d’octets/jetons et de profondeur. Imports,
fonctions de ressources URL/image, échappements, expressions, règles @ non
acceptées et déclarations malformées sont omis avec des diagnostics. Il s’agit
d’un sous-ensemble CSS restreint, pas d’un analyseur CSS complet. Les liens vers
des feuilles distantes exigent toujours `allowRemoteResources` ; scripts et
gestionnaires d’événements exigent la décision distincte `allowActiveContent`.
Les appelants utilisant déjà cette option plus large conservent les styles
incorporés.

La dérivation stricte conserve le **premier** Supplement HTML accepté du
catalogue et ignore les autres fichiers associés, conformément au §4.2.2,
page physique 12 de Deriving HTML from PDF 1.2. Certains PDF LaTeX contiennent
plutôt des fragments HTML de style séparés pour les listes et l’alignement.
`mergeCatalogHtmlSupplements` sélectionne volontairement tous les Supplements
HTML acceptés dans l’ordre PDF, tout en ignorant les autres fichiers associés
du catalogue. Le diagnostic `catalog-html-supplements-merged` signale la
combinaison de plusieurs fragments. Ce profil d’interopérabilité optionnel
s’écarte de la règle stricte du Supplement unique ; son activation seule
n’autorise ni feuille de style, ni script, ni réseau. Tous les octets incorporés
sélectionnés restent soumis au budget cumulatif de ressources du lecteur.

La pièce jointe originale conservée dans `tests/Fixtures/latex-associated-styles`
exerce les deux fragments du catalogue, les variantes d’alignement répétées et
la branche Safari `@supports`. Sa source contient la déclaration invalide
`border margin-left:1em`, diagnostiquée et omise, sans réparation implicite.
L’oracle navigateur utilise la fixture LuaLaTeX reproductible `latex-layout.pdf`,
avec listes imbriquées et deux équations alignées, et compare le CSS filtré à ses
fragments associés originaux dans Chromium et Firefox. Un contrôle distinct
vérifie que les conditions `@supports`/`@media` imbriquées modifient effectivement
les styles calculés. Ces fixtures finies ne prouvent pas la prise en charge de
tous les documents LaTeX, mécanismes CSS ou technologies d’assistance.

## Garder les libellés de liste près du contenu bloc

Un `LBody` dérivé peut contenir des paragraphes ou des éléments de regroupement.
Un conteneur simplement inline laisse ces blocs commencer à la ligne suivante,
avec le libellé seul au-dessus. `ListLayout` fournit deux colonnes CSS uniquement
aux éléments `LI` générés dont les enfants sont un `Lbl` et un `LBody` contenant
des blocs. Il conserve l’ordre source, les identifiants, les paragraphes et les
listes imbriquées. Le premier bloc commence sans marge haute par défaut. Toutes
les règles générées utilisent `:where()` de spécificité nulle : les styles auteur
de l’item, du libellé et du paragraphe restent prioritaires. Les corps purement
textuels, listes de définitions et listes HTML explicites sont inchangés.
Le diagnostic `list-block-layout` décrit cette adaptation visuelle, permise par
la note sur les styles de libellés du §4.3.5.4, page physique 28.

L’oracle navigateur vérifie la vraie liste LaTeX à 1200 et 320 pixels CSS : les
libellés restent près de la première ligne, les paragraphes reviennent à la ligne
sans débordement et la liste imbriquée reste intacte. Il vérifie aussi la priorité
des styles auteur explicites d’affichage et de marge. L’ordre des cellules
mathématiques et le CSS source sont conservés ; le MathML de cette fixture place
les numéros d’équation à gauche, et le convertisseur ne les déplace pas pour
imiter leur position physique dans le PDF.
