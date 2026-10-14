# PDFToolkit PDF vers HTML

[English](README.md) · [Français](README.fr.md)

Dériver du HTML sémantique depuis un PDF balisé avec des objets PHP explicites,
selon **Deriving HTML from PDF 1.2** (PDF Association, septembre 2026).

Ce package optionnel conserve la dérivation hors du core PDF. Il exploite structure
logique et contenu Unicode plutôt que de deviner l'ordre de lecture à partir des
positions des caractères. Il ne reproduit pas la mise en page et ne promet pas
de convertir tout PDF.

## Installer

```sh
composer require "pdftoolkit/pdf-to-html:^0.1.0"
```

PHP 8.3+ en 64 bits avec DOM et mbstring est nécessaire. La CI vérifie PHP 8.3,
8.4 et 8.5. Composer installe le core et le lecteur PDF comme dépendances ;
installer seulement le core n'installe pas ce module.

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
- [Sécurité](docs/fr/security.md) : budgets, validation HTML/CSS/URL et contenu actif.
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
