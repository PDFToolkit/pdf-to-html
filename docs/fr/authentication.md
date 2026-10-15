# Authentification native par mot de passe

[English](../authentication.md) · [Français](authentication.md)

La version 0.3.0 accepte l’authentification Standard de pdf-reader 0.3,
configurée explicitement. Le convertisseur par défaut n’ouvre toujours aucun
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
L’authentification native ne vérifie ni signatures CMS, ni confiance des
certificats, ni PDF MAC. Le module HTML ne prétend jamais le faire.

Le port `AuthenticationProvider` existant reste disponible pour un modèle source
authentifié séparément. Il n’est sollicité que lorsque le lecteur signale une
entrée chiffrée vérifiée par `EncryptedPdfException`. Les erreurs
d’interprétation, mots de passe natifs incorrects et gestionnaires non acceptés
ne le déclenchent pas par recherche dans le texte des erreurs. Ce port doit
renvoyer de véritables données authentifiées et des permissions vérifiées.

[NativeAuthenticationTest](../../tests/NativeAuthenticationTest.php) utilise de
vrais PDF balisés produits par qpdf en AES-128/R4 et AES-256/R6, identifiants
utilisateur/propriétaire, échecs et réutilisation avec une source non chiffrée.
L’[oracle indépendant](../../tools/validate_authentication.py) contrôle leurs
empreintes, les déchiffre avec pypdf/qpdf et analyse le HTML réel selon HTML5.
Il génère aussi un document chiffré neuf avec des object streams via qpdf. Les
mots de passe publics des fixtures sont des données de test ; aucun identifiant
de production ne figure dans le manifeste ou les diagnostics.
