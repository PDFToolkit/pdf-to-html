# Authentification native par mot de passe

[English](../authentication.md) · [Français](authentication.md)

La version 0.4 nécessite pdf-reader 0.4 et accepte son authentification
Standard configurée explicitement. Le convertisseur par défaut n’ouvre aucun
fichier chiffré sans opération d’authentification sélectionnée. L’application
fournit les octets et son identifiant ; aucune bibliothèque ne recherche de mot
de passe et le nom descriptif n’est jamais ouvert implicitement.

```php
use PDF\Html\PdfToHtml;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Reading\Security\StandardPasswordAuthentication;
use PDF\Security\Saslprep;

// L’application fournit l’identifiant Unicode brut ; sa préparation est explicite.
$reader = new DocumentReader(
    authentication: new StandardPasswordAuthentication(
        (new Saslprep())->prepare($rawUtf8Password),
    ),
);
$result = (new PdfToHtml(reader: $reader))->convert(
    new FileBytes($pdfBytes),
    'protected.pdf',
);
```

Pour les révisions Standard historiques, fournir un `PdfDocEncodedPassword`
contenant les octets PDFDocEncoding explicites, à la place de `PreparedPassword`.
Ces encodages correspondent à deux contrats distincts : un mot de passe UTF-8
arbitraire n’est pas réinterprété silencieusement comme des octets historiques.
Les backends AES nécessitent OpenSSL, sauf sélection explicite d’un autre
backend. Le [guide du lecteur](https://pdftoolkit.github.io/fr/modules/reader/authentication.html)
décrit les révisions, filtres cryptographiques et restrictions pris en charge.

Reader 0.3.1 lie `EncryptMetadata=false` à la véritable référence `Metadata` du
catalogue du document. Les métadonnées des pages et objets utilisent toujours
leur filtre cryptographique sélectionné, sauf sélection explicite d’Identity.
Les noms `Type` indirects des flux sont résolus avant de choisir le déchiffrement
des fichiers incorporés ou des flux ordinaires. Le même correctif traite la clé
V4 fixée à 128 bits en l’absence de Length global et les entrées de nom standard
ignorées dans CF. Ces règles appartiennent au lecteur ; la dérivation reçoit
les objets source authentifiés ainsi obtenus. Le core 0.9 fournit le vérificateur
MAC natif utilisé par le lecteur.

`ReadPdfSource` reçoit `ReadDocument::permissions()` seulement après une
authentification réussie. Il construit `AuthenticatedPermissions` avec le masque
effectif : l’utilisateur authentifié conserve les restrictions `/P` de la source,
tandis que l’accès propriétaire authentifié utilise `-4`. Le body HTML porte
`data-pdf-perms` et le diagnostic `viewer-permissions` rappelle que l’application
destinataire doit appliquer ces restrictions. Exporter un masque ne réalise pas
le contrôle d’accès du navigateur. Une entrée non chiffrée n’a pas de permissions
fabriquées ; réutiliser un convertisseur ne réutilise pas les permissions d’un
document précédent.
`PdfToHtml` contrôle aussi les adaptateurs personnalisés : supprimer ou élever
un masque natif vérifié est une erreur de dérivation, sans perte silencieuse
des restrictions.

Un mot de passe incorrect produit
`PDF\Reading\Security\AuthenticationFailed`, sans nouvelle tentative par un
autre fournisseur. Les gestionnaires, filtres cryptographiques ou exigences de
PDF MAC source non pris en charge restent des erreurs explicites du lecteur.
Reader 0.4 vérifie les MAC PDF source avant l’interprétation du contenu protégé.
Cela ne valide pas mathématiquement une signature CMS, n’établit pas la confiance
dans un certificat ou une TSA et n’authentifie pas le HTML dérivé.

Le port `AuthenticationProvider` fournit un `ReadDocument` authentifié des octets
exacts de l’entrée avant toute interprétation balisée. Il n’est sollicité que lorsque le lecteur signale une
entrée chiffrée vérifiée par `EncryptedPdfException`. Les erreurs
d’interprétation, mots de passe natifs incorrects et gestionnaires non acceptés
ne le déclenchent pas par recherche dans le texte des erreurs. Ce port doit
renvoyer de véritables données authentifiées et des permissions vérifiées. Un
autre fichier, l’absence de permissions ou un MAC manquant sous une politique
obligatoire sont refusés avant `TaggedPdfSource::read()`.

## Exiger l’intégrité de la source

```php
use PDF\Reading\Security\SourceIntegrityPolicy;

$converter = new PdfToHtml(
    reader: $reader,
    sourceIntegrity: SourceIntegrityPolicy::RequirePdfMac,
);
$result = $converter->convert(new FileBytes($pdfBytes), 'protected.pdf');
```

Le convertisseur conserve ou renforce la politique du lecteur injecté. Si l’un
exige un MAC PDF, les voies native et fournie doivent en fournir un. Un lecteur
permissif injecté est renforcé avant la lecture du catalogue. Le défaut compatible
`AcceptUnprotected` du convertisseur ne peut pas relâcher un lecteur obligatoire.
Ce défaut vérifie tout MAC signalé par le mécanisme natif et accepte aussi les
PDF ordinaires sans MAC. La politique obligatoire refuse ces fichiers, dont une
source privée de tous ses indices de chiffrement ou de MAC.

Le lecteur prend en charge Standard R5–R7, les MAC Standalone/AttachedToSig et
les horodatages documentaires, avec CMS borné et couverture exacte des octets
originaux. Son [contrat MAC](https://pdftoolkit.github.io/fr/modules/reader/source-mac.html)
précise les limites cryptographiques et de ressources. La protection dépend de
la clé de chiffrement : son détenteur peut émettre un nouveau MAC. Elle
n’accorde aucune permission d’usage supplémentaire. Les politiques de sécurité
HTML/CSS/URL/scripts s’appliquent après la vérification de la source.

Exécuter l’[exemple complet](../../examples/04-authenticate-source-mac.php) avec
la fixture et le mot de passe de test fournis explicitement :

```console
PDFTOOLKIT_PASSWORD=native-user php examples/04-authenticate-source-mac.php
```

Dans PowerShell, définir `$env:PDFTOOLKIT_PASSWORD = 'native-user'`, puis lancer
`php examples/04-authenticate-source-mac.php`. Les identifiants de production
viennent de l’application, avec préparation explicite ; aucun repli n’est deviné.

## Migration du port d’authentification en 0.4

Le port reçoit désormais la politique d’intégrité effective et renvoie le graphe
source, à la place de l’ancien retour direct de `TaggedDocument` :

```php
use PDF\Html\AuthenticationProvider;
use PDF\Reading\ReadDocument;

final readonly class AuthenticatedReaderProvider implements AuthenticationProvider
{
    public function __construct(private StandardPasswordAuthentication $authentication)
    {
    }

    public function authenticate(FileBytes $encryptedFile, string $filename, SourceIntegrityPolicy $sourceIntegrity): ReadDocument
    {
        return (new DocumentReader(
            authentication: $this->authentication,
            sourceIntegrity: $sourceIntegrity,
        ))->read($encryptedFile);
    }
}
```

Conserver les octets originaux et le contexte de déchiffrement authentifié du
document. Renvoyer un graphe lu depuis un autre PDF réécrit en clair ne préserve
pas l’identité source. Implémenter la capacité explicite `DocumentAuthentication`
du lecteur pour intégrer un autre déchiffreur. Le fournisseur est du code de
confiance de l’application ; implémenter ce port ne prouve pas que sa
cryptographie personnalisée vérifie un MAC. Le convertisseur contrôle identité,
permissions et résultat obligatoire, puis utilise le même adaptateur et les
mêmes contrôles de permissions que la voie native.

[NativeAuthenticationTest](../../tests/NativeAuthenticationTest.php) utilise de
vrais PDF balisés produits par qpdf en AES-128/R4 et AES-256/R6, identifiants
utilisateur/propriétaire, échecs et réutilisation avec une source non chiffrée.
L’[oracle indépendant](../../tools/validate_authentication.py) contrôle leurs
empreintes, les déchiffre avec pypdf/qpdf et analyse le HTML réel selon HTML5.
Il génère aussi un document chiffré neuf avec des object streams via qpdf. Les
mots de passe publics des fixtures sont des données de test ; aucun identifiant
de production ne figure dans le manifeste ou les diagnostics.

[SourceIntegrityTest](../../tests/SourceIntegrityTest.php) et l’[oracle MAC/HTML5](../../tools/validate_source_integrity.py)
ajoutent de vrais PDF balisés R6/R7 indépendants, les deux emplacements MAC,
les deux voies d’authentification, les masques utilisateur/propriétaire, erreurs
de mot de passe et corruptions. Ils refusent aussi le graphe MAC valide d’un
autre fichier, les relâchements de politique et les élévations de permissions.
La confiance dans les certificats et la validation mathématique des signatures
sont des capacités distinctes, sans déduction à partir de ces contrôles.
