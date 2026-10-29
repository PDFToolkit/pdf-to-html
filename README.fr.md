# PDFToolkit PDF vers HTML

[English](README.md) · [Français](README.fr.md)

Dériver du HTML sémantique depuis un PDF balisé avec des objets PHP explicites,
selon **Deriving HTML from PDF 1.2** (PDF Association, septembre 2026).

Ce dépôt a été créé spécialement pour implémenter les règles de
[Deriving_HTML_from_PDF_1-2.pdf](https://pdfa.org/download-area/specifications/Deriving_HTML_from_PDF_1-2.pdf).
[Copie conservée dans ce dépôt](resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf).
La version 0.8.1 implémente les mécanismes de dérivation de la référence avec des
profils de sécurité explicites, l’authentification native par mot de passe/clé publique/MAC et un
rendu fourni par l’application. Le [guide de couverture](docs/fr/coverage.md) et le
[registre des clauses](docs/fr/requirements.md) décrivent les comportements
concrets, les preuves exécutables et les frontières d'intégration.

Ce package optionnel conserve la dérivation hors du core PDF. Il exploite structure
logique et contenu Unicode plutôt que de deviner l'ordre de lecture à partir des
positions des caractères. Il ne reproduit pas la mise en page et ne promet pas
de convertir tout PDF.

## Installer

```sh
composer require "pdftoolkit/pdf-to-html:^0.8"
```

PHP 8.3+ en 64 bits avec DOM et mbstring est nécessaire. La CI vérifie PHP 8.3,
8.4 et 8.5. Composer installe le core et le lecteur PDF comme dépendances ;
installer seulement le core n'installe pas ce module.

La version 0.8.1 exige le Reader `^0.7` et le core stable `^0.11 || ^0.12`.
Depuis sa version 0.6, le Reader prend en charge l’authentification par clé publique sur sélection explicite et normalise les permissions historiques s3.
Le lecteur vérifie
les MAC sources ISO/TS 32004 signalés avant l’interprétation du contenu.
La politique explicite `SourceIntegrityPolicy::RequirePdfMac` refuse aussi
les entrées non protégées.

Le décodage des images utilise d’abord le moteur natif compatible du Reader,
puis la bibliothèque PHP optionnelle si elle est installée :
`composer require pdftoolkit/pdf-codecs:^0.1`. Aucun package n’est installé
automatiquement. Les [images décodées](docs/fr/image-codecs.md) conservent la
précision, les profils couleur et l’opacité prise en charge dans le PNG ; les
autres représentations gardent un diagnostic explicite et leur source originale.

## Lire, dériver, puis écrire

L'application fournit les octets et choisit où écrire le résultat. Lecture,
dérivation sémantique et opérations sur les fichiers restent séparées :

```php
use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;

$document = (new DocumentReader())->read(new FileBytes($pdfBytes));
$source = (new ReadPdfSource())->read($document, 'document.pdf');
$result = (new HtmlDeriver())->derive($source);
$html = $result->html;
```

`PdfToHtml` peut coordonner ces mêmes étapes sans cacher d'accès aux fichiers :

```php
$result = (new \PDF\Html\PdfToHtml())->convert(
    new FileBytes($pdfBytes),
    'document.pdf',
);
```

Son constructeur reçoit lecteur, adaptateur, dériveur et fournisseur optionnel
d'authentification comme objets explicites. La conversion reçoit toujours des
octets ; le nom source est descriptif et n'est pas ouvert implicitement.

Pour un fichier balisé chiffré, injecter un `DocumentReader` configuré avec
`StandardPasswordAuthentication`, ou `PublicKeyAuthentication`. L’adaptateur natif exporte les permissions effectives authentifiées dans `data-pdf-perms` ; un mot de passe
incorrect échoue avant la dérivation. Le port d’authentification existant reste
disponible. Le [guide d’authentification](docs/fr/authentication.md) précise les
encodages et frontières des gestionnaires de sécurité, PDF MAC et permissions
appliquées par le viewer. Le port d’authentification 0.4 renvoie `ReadDocument`
pour les octets fournis exacts, en conservant permissions effectives et politique
d’intégrité demandée avant adaptation. Le guide explique la migration du port
et l’[exemple 04](examples/04-authenticate-source-mac.php). L’authentification
source ne protège pas le HTML produit et n’établit aucune confiance de certificat.

Le MathML conserve sa mise en page mathématique native. La version 0.5.1 corrige
le mapping automatique de Layout vers CSS qui pouvait émettre `display:inline`
sur le MathML. Un `<math>` inline utilise son comportement natif ; Placement
Block utilise l’attribut MathML `display="block"` si aucun display MathML
explicite n’est fourni. Le CSS fourni par l’auteur reste explicite et peut
remplacer les valeurs par défaut du navigateur. Voir
[le placement MathML et le rendu navigateur](docs/fr/coverage.md#placement-mathml-et-rendu-navigateur)
pour les priorités, classes partagées et limites de rendu.

L'adaptateur source par défaut reçoit la politique et les limites du dériveur.
Les contenus Alternative/Supplement sont sélectionnés avant de décoder le contenu
PDF remplacé, avec la même politique que la sortie HTML. Lors d'un assemblage
manuel, fournir la même politique à `ReadPdfSource` et `HtmlDeriver`.

La lecture native contrôle les associations inverses ParentTree en mode permissif
et renvoie les incohérences dans les diagnostics. Utiliser
`StructureValidationMode::Strict` sur `ReadPdfSource` pour les refuser. Ce contrôle
d'association de structure ne constitue pas une certification WTPDF/PDF/UA.

## Choisir les capacités de rendu et de formulaire

Texte logique, correspondances Unicode, structure, tableaux, labels, liens,
Image XObjects et encodages inline acceptés sont lus directement. Les graphiques
natifs conservent programmes d'instructions, état, ressources et contexte source.
`GraphicPdfExporter` produit un PDF isolé contenant les opérations de peinture
sélectionnées ; `RasterContentRenderer` le transmet à un `GraphicsRasterizer`
fourni explicitement. La bibliothèque n'installe ni ne lance un moteur de rendu.
L'export conserve la version PDF effective de la source, la transparence et le
contexte du contenu optionnel. Les programmes de page utilisent la CropBox
physique ; les références d'objet Form autonomes conservent le XObject Form,
ses limites transformées, clipping, matrice, transparence et contenu optionnel.
Une BBox de structure ou un viewport explicite en coordonnées de page peut
remplacer ces limites. Aucun cadrage par les pixels peints n'est calculé.
Le [guide graphique](docs/fr/graphics.md) fournit un exemple d'intégration exécutable.

La version 0.6.0 ajoute `ContentAppearanceRenderer` pour réutiliser ce moteur
explicite avec les apparences de widgets, dont les états normal/appuyé/survol
ajustés au rectangle d’annotation. Le CSS natif applique les modes de surbrillance
PDF en conservant les vrais contrôles et la priorité des styles de l’auteur.

Les champs signés proposent un popover HTML natif contenant une explication.
Injecter `ReadPdfSource(signatureValidation: $provider)` pour valider le PDF
original via un `SignatureValidationProvider` explicite. L’adaptateur fourni
`SignatureOutputValidation` vérifie la signature mathématique avec un certificat
choisi et rapporte `integrity-verified` ; il ne décide ni de la confiance du
certificat, ni de sa révocation, ni de l’autorisation des révisions ultérieures.
Le HTML dérivé reste non signé. Voir le [guide des signatures](docs/fr/signatures.md).

ResetForm utilise la valeur par défaut PDF `/DV`, y compris son absence, et
respecte sélection, noms parents et exclusion. SubmitForm possède des règles
explicites d'export HTML, de sélection et de noms de mapping. Les deux demandent
une autorisation `FormActionPolicy` ; le runtime de formulaire propre à la
bibliothèque peut être activé sans exporter les scripts PDF :

```php
$policy = new \PDF\Html\DerivationPolicy(
    formActions: new \PDF\Html\Forms\FormActionPolicy(allowReset: true),
);
$converter = new \PDF\Html\PdfToHtml(deriver: new HtmlDeriver($policy));
$result = $converter->convert(new FileBytes($pdfBytes), 'document.pdf');
```

ECMAScript PDF et ressources distantes possèdent des options séparées. Lire les
[politiques](docs/fr/policies.md) avant de les activer ou de servir les ressources
renvoyées.

`DerivationResult` renvoie explicitement HTML, CSS, ressources et diagnostics.
Les inspecter avant publication. Écrire chaque ressource à côté du HTML sous son
nom retourné, ou adapter ses URL de manière cohérente. Lorsque les scripts fiables
sont activés, `ScriptAssetEmitter` renvoie des fichiers externes
`pdf-script-<sha256>.js` dont dépend le HTML. Le modèle source immuable peut aussi être
construit directement par l'application, indépendamment de l'analyse de fichiers.

Exécuter l'exemple PDF réel :

```sh
php examples/01-derive-tagged-pdf.php tests/Fixtures/tagged.pdf _build/derived.html
```

Le script reçoit explicitement les chemins d'entrée/sortie, écrit le HTML et
chaque ressource dans le dossier de sortie, puis imprime empreintes et diagnostics
en JSON. Le [tutoriel central](https://pdftoolkit.github.io/fr/tutorials/35-deriver-html.html)
fournit le PDF d'entrée, ses pages rendues, le PHP exécutable et le HTML obtenu.

## Comprendre les frontières

- [Architecture](docs/fr/architecture.md) : objets source, rôles et sérialisation.
- [Couverture](docs/fr/coverage.md) : référence et frontières de l'adaptateur.
- [Registre des clauses](docs/fr/requirements.md) : règles, décisions et preuves.
- [Politiques](docs/fr/policies.md) : profils communs, formulaires, scripts et rendu.
- [Sécurité](docs/fr/security.md) : budgets, validation HTML/CSS/URL et contenu actif.
- [Authentification](docs/fr/authentication.md) : identifiants natifs et permissions utilisateur/propriétaire effectives.
- [Signatures](docs/fr/signatures.md) : validation liée à la source, interface d’état et frontières PKI.
- [Référence conservée](docs/fr/reference.md) : PDF original, licence et empreinte.
- [API complète et guides](https://pdftoolkit.github.io/fr/modules/to-html/).

La bibliothèque n'exécute aucun script et ne récupère pas implicitement de
ressource externe. Les règles ECMAScript de la référence s'interprètent avec la
politique de sécurité explicite ; elles ne promettent pas d'émuler l'API JavaScript
d'Acrobat. Un HTML sémantique ne certifie ni l'accessibilité ni PDF/UA.

## Développer

```sh
composer install
composer check
```

La [procédure de version](RELEASING.md) décrit la CI du candidat exact, les
validations indépendantes et le contrôle d'installation stable. Le code est sous
licence MIT ; la publication PDF Association conservée a sa propre licence CC-BY-4.0.
