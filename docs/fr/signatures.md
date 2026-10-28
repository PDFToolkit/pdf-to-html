# Valider les signatures PDF et expliquer leur état

[English](../signatures.md) · [Français](signatures.md)

Deriving HTML 1.2, §4.4.8.3.1, table 5, pages physiques 57–58, demande que l’état de la signature
source soit accessible depuis son contrôle dérivé. La version 0.6.0 fournit un
port explicite de validation et un popover HTML natif. Ces informations concernent
le PDF original : la conversion ne signe ni n’authentifie le HTML.

## Fournir explicitement la validation

`ReadPdfSource` accepte un `SignatureValidationProvider` optionnel. Pour chaque
champ signé, il fournit une `SignatureValidationRequest` contenant le
`ReadDocument` exact, le dictionnaire de signature et le nom du champ. Renvoyer une
`SignatureValidationResult` pour ce même objet requête, avec un
`SignatureValidationStatus` et une explication UTF-8 non vide. `assertFor()` refuse
un résultat lié à une autre requête, même si son nom de champ est identique.

Le `SignatureOutputValidation` fourni utilise le `SignatureOutputVerifier`
explicite du core et une `CertificateChain` choisie par l’application :

```php
use PDF\Html\HtmlDeriver;
use PDF\Html\Reading\ReadPdfSource;
use PDF\Html\Signatures\SignatureOutputValidation;
use PDF\Reading\DocumentReader;
use PDF\Reading\FileBytes;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslSignatureOutputVerifier;
use PDF\Security\X509Certificate;
use PDF\Syntax\Object\StringObject\HexadecimalString;

// Les deux chaînes d’octets sont fournies explicitement par l’application.
$certificate = new CertificateChain(
    new X509Certificate(new HexadecimalString($signerCertificateDer)),
);
$validation = new SignatureOutputValidation(
    new OpenSslSignatureOutputVerifier(),
    $certificate,
);
$document = (new DocumentReader())->read(new FileBytes($pdfBytes));
$source = (new ReadPdfSource(signatureValidation: $validation))
    ->read($document, 'signed.pdf');
$result = (new HtmlDeriver())->derive($source);
```

Aucun certificat du PDF n’est sélectionné comme décision implicite de confiance.
Cet adaptateur vérifie les signatures mathématiques acceptées avec le certificat
fourni ; il ne construit pas de chemin de certification de confiance et ne contacte
aucun service de révocation. `SignatureVerificationSource` vérifie aussi deux
segments ByteRange non négatifs commençant à l’octet zéro, un intervalle exclu
correspondant au jeton Contents physique exact et une fin sur une révision physique
complète. Son `RevisionHistoryReader` optionnel est un objet explicite. Une
signature reconstruite sans provenance physique conservée, un format non accepté
ou un horodatage documentaire nécessite un autre fournisseur applicatif et produit
ici `indeterminate`. Les plages mal formées détectées directement et les échecs
mathématiques produisent `invalid`. Une inspection physique incomplète, dont une
limite du lecteur, produit `indeterminate` et ne démontre pas un échec cryptographique.

Exécuter l’[exemple 05](../../examples/05-verify-pdf-signature.php) avec des
chemins locaux explicites pour l’entrée, le certificat signataire et la sortie :

```sh
php examples/05-verify-pdf-signature.php input.pdf signer.der _build/signed.html
```

La CLI accepte aussi un certificat PEM unique. Elle écrit HTML et ressources
retournées, puis imprime empreintes source/certificat/HTML et diagnostics en JSON.
Examiner les états des champs dans la sortie ; réussir la conversion ne signifie
pas que toutes les signatures sont valides. Pour une démonstration reproductible,
utiliser `tests/Fixtures/signatures/signed-padded.pdf` et `signer.der` du même
dossier de fixtures. Ce sont des données publiques de test, pas des identifiants
de confiance.

## Distinguer les états

| État | Signification |
| --- | --- |
| `unsigned` | Le champ source n’a pas de valeur de signature. Le lecteur n’appelle pas le fournisseur de validation. |
| `not-validated` | Une signature existe, mais aucun résultat n’a été demandé/fourni. |
| `integrity-verified` | L’adaptateur fourni a authentifié les octets couverts avec le certificat choisi. Il n’a validé ni confiance du certificat, ni révocation, ni modifications PDF ultérieures. |
| `invalid` | Le fournisseur choisi a détecté une condition de validation non satisfaite ; lire son explication. |
| `indeterminate` | Le fournisseur choisi ne peut pas établir de résultat dans son périmètre. |
| `valid` | Disponible pour un fournisseur applicatif qui applique sa politique complète déclarée. L’adaptateur d’intégrité fourni ne renvoie jamais cet état. |

Une signature valide sur une ancienne révision ne démontre pas que les changements
suivants respectent DocMDP/FieldMDP/UR. Choix des racines de confiance, date de
validation, preuves de révocation, validation d’horodatage et analyse des
permissions de révision appartiennent au fournisseur applicatif. Il doit expliquer
les contrôles effectués ; une énumération d’état seule n’est pas une politique de
validation. Déchiffrement, vérification MAC du PDF source et permissions du viewer
sont des mécanismes distincts décrits dans le [guide d’authentification](authentication.md).

Le bouton porte `data-pdf-signature-status`, `popovertarget` et
`aria-describedby` ; le popover voisin contient l’explication échappée. Son
activation à la souris ou au clavier utilise le navigateur, sans autorisation de
JavaScript PDF. Les hôtes visant des navigateurs sans popover natif doivent fournir
une présentation de secours appropriée. L’application qui construit directement
le modèle immuable peut fournir `Widget::$signatureValidation` ; l’ancien
`signatureStatus` reste une métadonnée d’affichage, sans preuve de vérification.

## Preuves reproductibles et limites

[SignatureOutputValidationTest](../../tests/Signatures/SignatureOutputValidationTest.php)
et [SignatureValidationReadingTest](../../tests/Reading/SignatureValidationReadingTest.php)
exercent la liaison à la source exacte, les plages invalides, l’intégration des
champs et les états de vérification.
[validate_signatures.py](../../tools/validate_signatures.py) contrôle des fixtures
PDF/CMS indépendantes conservées avec pypdf, asn1crypto, cryptography, OpenSSL et l’adaptateur PHP. Le
certificat de test auto-signé volontairement expiré démontre la distinction entre
intégrité mathématique et confiance PKI ; ce n’est ni un identifiant de déploiement
ni une recommandation de confiance.

[validate_signature_popovers.cjs](../../tools/validate_signature_popovers.cjs)
contrôle les vrais boutons dérivés par PHP dans Chromium et Firefox, normalement
avec JavaScript de page désactivé et requêtes réseau bloquées. Un cas readonly
séparé active le runtime de la bibliothèque et vérifie que consulter le résultat
reste possible sans modifier le champ. Rapport et captures couvrent texte
d’état, échappement, activation souris et clavier. Réussir un cas ou une exécution
navigateur prouve ces cas exercés, sans conformité universelle des signatures PDF
ou de l’accessibilité. Conserver chaque rapport avec son commit source et ses
versions d’outils.
