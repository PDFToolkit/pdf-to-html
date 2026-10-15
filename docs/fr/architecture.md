# Architecture de la dérivation HTML

[English](../architecture.md) · [Français](architecture.md)

## Séparer lecture de fichiers et traitement sémantique

`PDF\Reading` possède syntaxe PDF, références croisées, flux et résolution bornée.
`PDF\Html\Reading\ReadPdfSource` adapte un document lu en
`PDF\Html\Model\TaggedDocument`. Son périmètre d'entrée est plus restreint que
celui du modèle source assemblé directement par une application.

Le core conserve ses objets PDF et son writer. Ce module optionnel possède la
dérivation HTML ; ni le core ni le lecteur ne dépendent de lui.

## Conserver des objets source explicites

Un `TaggedDocument` contient structure, rôles, attributs de classes, langue, titre
et fichiers associés. `StructureElement` conserve rôle, namespace, propriétés,
attributs et enfants ordonnés. Texte, contenu marqué, graphiques, images, liens
et widgets représentent des informations distinctes. La résolution appartient
à un document, sans singleton, registre global ni recherche implicite de ressource.

`GraphicProgram` conserve une plage d'instructions, opérandes/ressources
originaux, rectangle de page, matrice et appel Form englobant. Les nœuds
désignent ces programmes au lieu de réduire un chemin/shading au nom de
son opérateur de peinture.
Le contexte de page conservé fournit son groupe de transparence et la configuration
du contenu optionnel du document lors de l'export des graphiques sélectionnés.

[`PageTransform`](../../src/Reading/PageTransform.php) est un objet readonly
explicite de `PDF\Html\Reading`, construit depuis un `ReadPage`. Sa propriété
publique `matrix` transforme l'espace utilisateur de page par défaut en points
PDF physiques avec Rotate et UserUnit. UserUnit absent ou null direct/indirect
vaut 1 ; les autres valeurs doivent être positives et finies. Lecture et export
partagent cette transformation afin qu'un viewport explicite en coordonnées de
page ne reçoive pas par erreur une matrice locale du Form.

`ParentTreeValidator` compare les associations directes aux entrées inverses
StructParent/StructParents. Son mode permissif/strict choisit entre diagnostic
et erreur pour les incohérences ; cycles et budgets restent des erreurs.
Ce contrôle ne valide pas chacune des exigences WTPDF.

`StructureProperties` distingue Lang, ActualText, Alt, E, identifiants, classes et
Ref. Un texte de remplacement n'est pas une description accessible.

## Dériver une sortie sémantique

`HtmlDeriver` coordonne rôles, attributs, contenu et réparation du modèle de
contenu HTML. Des nœuds HTML explicites précèdent la sérialisation. La dérivation
suit l'ordre logique des enfants ; les rôles qualifiés par namespace sont résolus
avant de choisir le HTML.

HTML ne peut pas imbriquer directement toute structure PDF valide. Réparer son
modèle de contenu et reproduire la mise en page sont deux responsabilités.
Titres, légendes, étiquettes, listes, tableaux, figures et widgets ont donc leurs
propres règles de traitement.

`AssociatedFileSelection` applique la politique commune avant d'interpréter le
contenu natif. Une passe structurelle enregistre ActualText et les
Alternative/Supplement acceptés avant de traiter une page partagée. Alternative
remplace les descendants ; Supplement remplace le contenu direct et conserve
la sous-structure. Une association bloquée laisse l'original disponible.

`PageContentIdentifiers` associe la navigation au véritable parent du contenu
dérivé de chaque page. Les remplacements conservent les bonnes ancres source
sans les attribuer à un contenu sans rapport.

## Renvoyer les preuves avec le résultat

`DerivationResult` renvoie HTML, CSS, ressources et diagnostics. L'application
choisit comment les conserver et les exposer. La dérivation ne récupère pas d'URL,
n'ouvre pas de nom de fichier arbitraire et n'exécute pas de script par effet de bord.

Avec le contenu actif fiable activé, `ScriptAssetEmitter` déplace le code des
scripts dans des ressources `pdf-script-<sha256>.js` nommées par leur contenu et
produit des `script[src]`. Cela préserve la grammaire JavaScript même si la source
contient une séquence HTML de fermeture de script, sans assainir le JavaScript.
L'appelant doit écrire toutes les ressources à côté du HTML ou adapter leurs URL
de manière cohérente ; l'exemple CLI réalise explicitement ces écritures.

Les actions propres à la bibliothèque utilisent une `FormActionPolicy` activée
séparément ; leur runtime ne nécessite pas de scripts PDF incorporés.
`FieldSelection`, `ResetFormAction` et `SubmitFormAction` conservent sélection
absente/vide, exclusion, `/DV` et options d'export. `WidgetBinding` identifie le
contrôle HTML final de chaque widget et garde la valeur partagée par nom PDF.

Les fragments MathML passent par un filtre de vocabulaire tenant compte des
namespaces. Le MathML de présentation et de contenu peut être conservé ; conserver
le balisage ne fournit ni moteur de rendu navigateur, ni calcul mathématique,
ni polyfill. Le vocabulaire étendu hors du sous-ensemble Core accepté produit un
diagnostic. Voir la [couverture et les limites navigateur](coverage.md).

Lire la [couverture](coverage.md) pour distinguer modèle/fichier/runtime et la
[sécurité](security.md) avant de traiter des documents non fiables.

## Configurer des limites indépendantes

`ReaderLimits` borne syntaxe et flux PDF. `ReadingLimits` ajoute les bornes de
l'adaptation : profondeur, nœuds, instructions, octets de contenu/texte,
correspondances, ressources et pages. `DerivationLimits` borne parcours sémantique,
octets de sortie, ressources et chaînes de rôles. Chaque conversion possède ses
propres budgets et caches.

`PdfToHtml` coordonne ces étapes injectables. Un `DocumentReader` configuré avec
`StandardPasswordAuthentication` authentifie et déchiffre les entrées acceptées
avant adaptation ; `ReadPdfSource` transmet ses permissions effectives vérifiées
au modèle source immuable. Un `AuthenticationProvider` optionnel reste une
capacité distincte de l’application. Conserver un dictionnaire `/Encrypt` ou
construire un objet de permissions n’implémente pas le déchiffrement. Voir
l’[authentification native](authentication.md) pour les identifiants et limites.

L'adaptateur par défaut reçoit politique et limites du dériveur. Les applications
assemblant les étapes séparément fournissent les mêmes objets ; voir les
[politiques](policies.md). Le port d’authentification distinct ne dépend que de l’exception typée
`EncryptedPdfException` du lecteur pour `/Encrypt` vérifié ; les erreurs
d'adaptation/filtres ne la déclenchent jamais par recherche de texte.

## Fournir les capacités d'interprétation et de rendu

`TaggedPdfSource` est le port public d'interprétation implémenté par `ReadPdfSource`.
Injecter une autre implémentation dans `PdfToHtml` permet d'obtenir le même modèle
immuable depuis une interprétation indépendante sans remplacer la dérivation.

Le troisième argument optionnel d'`HtmlDeriver` est `ContentRenderer` : le moteur
de l'application reçoit un `GraphicContent` ou un `ContentGroup` et renvoie un
`ImageContent` ou explicitement null. `DerivationPolicy::preferVisualFigures`
vaut false par défaut pour conserver le contenu sémantique des figures ; l'activer
demande un rendu du groupe par le moteur fourni. Cela n'installe ni ne lance un
moteur de rendu.

`RasterContentRenderer` coordonne `GraphicPdfExporter` et un `GraphicsRasterizer`
propre à l'application. L'export préserve les programmes sélectionnés et l'état
nécessaire au clipping/avancement du texte, réassocie les ressources et produit
un PDF isolé d'une page. L'application choisit le moteur et renvoie ses pixels.
De vrais contrôles Poppler vérifient l'export indépendamment ; l'intégration
reste explicite.
L'export conserve l'état hérité de rendu/clipping du texte, exclut les artefacts
imbriqués et préserve la version PDF effective de la source. Les programmes de
page utilisent la CropBox physique par défaut ; les Form racines conservent la
frontière XObject, BBox, Group et appartenance OC et utilisent les limites
transformées du Form. Leur matrice est composée une fois avec la transformation
de page. Une BBox de structure ou un `GraphicViewport` explicite en
coordonnées de page peut remplacer les limites de sortie. Les budgets d'instructions, octets et objets sont
cumulatifs pendant l'export ; la profondeur réelle du parcours est aussi bornée.
Voir le [guide graphique](graphics.md).

`InlineImageBoundaryProvider` étend les frontières de codecs natives sans
recherche heuristique d'un `EI` binaire. Le fournisseur interprète la frontière
exacte du contenu et consomme le budget source fourni.

Le quatrième argument optionnel est `PDF\Html\Forms\AppearanceRenderer`, qui
convertit les nœuds explicites d'apparence des widgets en nœuds HTML. Lire un flux
d'apparence PDF et implémenter son moteur visuel restent des tâches distinctes.
Ces deux capacités appartiennent à l'application, avec ses ressources et sa
politique d'exécution. Le package ne lance aucun processus externe implicitement.
