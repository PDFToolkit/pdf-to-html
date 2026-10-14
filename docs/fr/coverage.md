# Deriving HTML from PDF : couverture et frontières

[English](../coverage.md) · [Français](coverage.md)

**État de l'implémentation : PARTIEL.** Ce package implémente une partie de la
référence, avec une conversion PDF vers HTML exécutable et un modèle source
explicite. Il n'implémente pas chacune des règles de Deriving HTML from PDF 1.2.
La version 0.1.2 corrige les régressions précises décrites ci-dessous ; les
lacunes techniques restantes sont distinguées des restrictions de sécurité
volontaires.

## Périmètre de la référence

La référence conservée est **Deriving HTML from PDF 1.2**, septembre 2026,
spécification d'usage PDF Association pour les fichiers ISO 32000-2 bien balisés.
Elle compte 74 pages physiques, préliminaires et annexes compris. Elle ne définit
ni une conversion HTML vers PDF ni une reconstruction visuelle.

`resources/reference.json` répertorie ses sections. Répertorier une section ne
prouve pas l'implémentation de chacune de ses règles. Code publié, tests
exécutables, validation indépendante et diagnostics s'examinent ensemble.

## Trois périmètres à distinguer

1. **Modèle source et dérivation sémantique** : l'application fournit explicitement
   structure, contenu Unicode, attributs, widgets, fichiers associés et politiques.
2. **Adaptateur de fichiers PDF** : le lecteur retrouve ces informations dans les
   encodages, opérateurs et mécanismes acceptés. Un objet modélisé ne garantit pas
   l'ingestion de tout fichier externe qui l'utilise.
3. **Intégration navigateur/runtime** : servir le HTML, résoudre les ressources,
   accepter les soumissions et fournir un runtime JavaScript appartient à
   l'application. Renvoyer un script ne l'exécute ni ne l'émule.

## Lacunes techniques vérifiées

Ce sont des limites de l'implémentation. L'existence d'un objet, d'une interface
ou d'un diagnostic ne signifie pas que le mécanisme est terminé. Les numéros de
pages physiques désignent la référence conservée. Les contrôles de structure
s'appuient sur les connaissances ISO 32000-2 requises par la section 4.1 ; ce
package n'est pas un validateur WTPDF.

| Référence, pages physiques du PDF | Lacune restante et conséquence pratique | Frontière du code |
| --- | --- | --- |
| 4.4.3, [53–54](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=53) | Les images inline (`BI…ID…EI`) sont refusées. La conversion échoue au lieu de produire une image ou un placeholder conservant ses dimensions logiques. | [ContentInstructionReader](../../src/Reading/ContentInstructionReader.php). |
| 4.4/4.4.1/4.4.5, [52–54](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=52) | La lecture native ne conserve que des marqueurs d'opérateurs de peinture pour les chemins/shadings ; géométrie, opérandes, état graphique et ressources des shadings sont perdus. Injecter un moteur ne permet pas de retrouver ces informations. Le port de rendu nécessite actuellement des objets source construits manuellement avec suffisamment de données ; le rendu complet de groupes graphiques issus de fichiers reste absent. La référence permet d'omettre les chemins/shadings inutiles à la réutilisation, mais cela n'implémente pas son cas de fidélité visuelle. | [ContentReader](../../src/Reading/ContentReader.php), [GraphicContent](../../src/Model/GraphicContent.php), [ContentRenderer](../../src/ContentRenderer.php). |
| 4.4.2, [53](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=53) | Les vecteurs d'encodage des polices simples sont reconnus, mais les correspondances de secours entre noms de glyphes et Unicode restent incomplètes, notamment pour Symbol, ZapfDingbats et MacExpert. Sans ToUnicode exploitable, certains textes valides deviennent des nœuds graphiques omis avec diagnostic. | [FontTextDecoder](../../src/Reading/FontTextDecoder.php). |
| 4.6.4.1, [63–64](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=63) | Une Alternative acceptée peut remplacer l'élément lors de la dérivation sémantique, mais l'adaptateur lit encore au préalable le contenu qui sera remplacé. Un contenu non accepté ou mal formé qui devrait être ignoré peut donc faire échouer la conversion avant le remplacement. | [SourceReadSession](../../src/Reading/SourceReadSession.php), [DerivationSession](../../src/DerivationSession.php). |
| 4.4.8.3.1/tableau 5, [57](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=57), et sémantique de l'action ResetForm dans ISO 32000-2 | La sélection `/Fields` et les flags d'exclusion de ResetForm ne sont pas implémentés. Le bouton reset natif actuel réinitialise tout le formulaire HTML. L'adaptateur ne retrouve pas la sélection : une action PDF ciblée ne doit pas être considérée comme équivalente à cette sortie. | [SourceReadSession](../../src/Reading/SourceReadSession.php), [WidgetDeriver](../../src/Forms/WidgetDeriver.php). |
| 4.1/4.3.1, [11/16](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=11), et prérequis de structure logique ISO 32000-2 | Les parents de structure, MCID et présences sur les pages sont contrôlés dans le sens direct, mais les associations inverses de `ParentTree` ne sont pas validées. Une conversion réussie ne prouve pas la cohérence des deux sens d'association du document source. | [SourceReadSession](../../src/Reading/SourceReadSession.php). |
| 4.3.5.4.1, [28](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=28) | L'attribut Layout `Placement` est encore appliqué aux labels de listes. La référence recommande de l'ignorer pour `Lbl` dans les listes ; une valeur fournie peut toujours modifier leur comportement display/float. | [DerivationSession](../../src/DerivationSession.php), [AttributeDeriver](../../src/Attributes/AttributeDeriver.php). |

Les problèmes `/Fields`, correspondances de glyphes et ParentTree ne sont pas
des politiques de sécurité. Le refus des images inline et la perte de données
graphiques non plus. Ces frontières demandent une implémentation et des preuves
de régression, pas seulement une ligne supplémentaire dans la couverture.

## Régressions corrigées dans la version 0.1.2

- **Labels et portée des formulaires** — sections 4.3.5.4 et 4.3.5.11.1,
  pages physiques 28–29 et 37. [LabelDerivationTest](../../tests/LabelDerivationTest.php)
  contrôle les labels indirects avec correspondance de rôle à travers des groupes
  de structure/contenu, les formulaires interactifs imbriqués, les formulaires
  internes non interactifs qui ne doivent pas hériter d'une cible extérieure,
  les cibles des labels HTML explicites et les mappings de labels blocs selon
  leur contexte.
- **Remplacement avant décodage des glyphes** — sections 4.3.6.3 et 4.4.7.2,
  pages physiques 40–41 et 54. [ReadingReplacementTest](../../tests/ReadingReplacementTest.php)
  construit des fixtures PDF en octets contenant des correspondances Unicode
  incohérentes sous ActualText. Il contrôle les remplacements vides ou non vides
  sur une page partagée, les contenus MCR/Form descendants et les remplacements
  marqués imbriqués. Les correspondances invalides non remplacées et les parents
  de structure incohérents restent refusés ; le remplacement ne contourne pas
  la validation de structure. Ce correctif ne couvre pas encore les remplacements
  AF Alternative, comme indiqué ci-dessus.
- **Valeurs de champs partagées, événements propres aux widgets** — annexe B,
  page physique 71. [ReadingScenariosTest](../../tests/ReadingScenariosTest.php),
  `testSharedFieldWidgetActionsRemainDistinctWhenReadFromPdf`, lit deux widgets
  d'un même champ possédant des actions de focus distinctes et une validation
  commune. [ScriptDeriverTest](../../tests/Script/ScriptDeriverTest.php) et le
  [harness Node](../../tests/Script/runtime.cjs) vérifient des handlers focus/mouse-up
  distincts, des valeurs synchronisées, les valeurs communes rejetées et la
  compatibilité avec les anciens modèles mélangeant les actions. Les scripts
  propres aux widgets restent désactivés dans le profil sûr et comptabilisés dans
  les budgets cumulatifs de sortie.
- **Priorité des attributs explicites** — section 4.3.7.1, page physique 43.
  [StructureAttributePrecedenceTest](../../tests/StructureAttributePrecedenceTest.php)
  vérifie qu'un `title` HTML explicite prévaut sur E, que E fournit toujours le
  titre sans redéfinition et qu'un `display` HTML/CSS explicite prévaut sur la
  valeur inline par défaut du corps de liste.

Les objets [WidgetBinding](../../src/Script/WidgetBinding.php) immuables relient
le runtime aux identifiants HTML finaux. Le harness Node vérifie le véritable HTML
de Forms imbriqués, les contrôles homonymes étrangers, les renommages et les
identifiants ressemblant à des propriétés de prototype.

## Mécanismes implémentés et preuves

Ce tableau décrit les comportements exécutables du package, y compris les ports
explicites de l'application. Leurs tests n'établissent ni l'acceptation de tout
fichier d'entrée ni la certification de son contenu.

| Référence | Implémentation | Classes | Preuves exécutables |
| --- | --- | --- | --- |
| 4.1–4.2 | Titre/langue, head/body, styles des classes, association des formulaires et navigation par labels de pages ; lecture du titre XMP et des métadonnées source. | [DerivationSession](../../src/DerivationSession.php), [DocumentMetadataReader](../../src/Reading/DocumentMetadataReader.php) | [HtmlDeriverTest](../../tests/HtmlDeriverTest.php) : `testDocumentMetadataTextEscapingAndPageNavigation` ; [ReadingSourceTest](../../tests/ReadingSourceTest.php) : `testIndependentPdf20StructureContentAndMetadata`. |
| 4.3.1–4.3.4 | Ordre logique en profondeur, correspondances transitives de rôles et identité des objets namespace ; réparation HTML conservant contenu et unicité des identifiants. | [RoleResolver](../../src/RoleResolver.php), [ContentModelRepair](../../src/Html/ContentModelRepair.php) | [HtmlDeriverTest](../../tests/HtmlDeriverTest.php) : chaînes de rôles, namespaces distincts et cycles ; [HtmlBoundaryTest](../../tests/HtmlBoundaryTest.php) : liens/labels imbriqués, légendes, listes et budgets de réparation. |
| 4.3.5 | Transformations spécifiques des titres, légendes, étiquettes, rôles FENote/notes, listes/descriptions, tableaux, figures, formules MathML, code, liens et formulaires interactifs ou non. Le port de rendu accepte du contenu construit par l'application ; les graphiques de fichiers natifs ont les limites ci-dessus. | [DerivationSession](../../src/DerivationSession.php), [WidgetDeriver](../../src/Forms/WidgetDeriver.php), [ContentRenderer](../../src/ContentRenderer.php) | [HtmlDeriverTest](../../tests/HtmlDeriverTest.php) : descriptions, légendes, figures inline et Reference/Link ; [LabelDerivationTest](../../tests/LabelDerivationTest.php) : labels indirects/imbriqués ; [AdvancedDerivationTest](../../tests/AdvancedDerivationTest.php) : groupes visuels modélisés manuellement et adaptation ruby ; [six cas HTML5](../../tests/export-derivation-cases.php), dont code et MathML. |
| 4.3.6 | Lang, ActualText même vide, Alt, E, identifiants et liens issus de Ref restent distincts ; les remplacements conservent les ancres de pages et les cibles supprimées ne créent pas de lien cassé. | [StructureProperties](../../src/Model/StructureProperties.php), [SourceValues](../../src/Reading/SourceValues.php), [DerivationSession](../../src/DerivationSession.php) | [HtmlDeriverTest](../../tests/HtmlDeriverTest.php) : remplacement/expansion et contenu marqué ; [AdvancedDerivationTest](../../tests/AdvancedDerivationTest.php) : ancres conservées et cibles supprimées ; [ReadingScenariosTest](../../tests/ReadingScenariosTest.php) : identifiants binaires et diagnostics de texte optionnel invalide. |
| 4.3.7 | Priorité explicite des propriétaires pour les classes et attributs directs ; List, Table, Layout, PrintField, HTML/CSS/ARIA, NSO correspondant et UserProperties produisent attributs/CSS ou diagnostics. Conversion des points PDF en pixels CSS. | [AttributeDeriver](../../src/Attributes/AttributeDeriver.php), [ClassName](../../src/ClassName.php) | [AttributeDeriverTest](../../tests/Attributes/AttributeDeriverTest.php) : propriétaires, namespaces, disposition et conversions ; [HtmlDeriverTest](../../tests/HtmlDeriverTest.php) : priorité classes/attributs directs. |
| 4.4.1–4.4.7 | Lecture du texte marqué, ToUnicode incorporé, encodages simples acceptés, Form XObjects et Image XObjects acceptés. `cm`, Form Matrix, q/Q, rotation et UserUnit composent les dimensions logiques des images. Les chemins/shadings natifs ne conservent que des marqueurs d'opérateurs de peinture ; les images inline sont refusées. | [ContentReader](../../src/Reading/ContentReader.php), [ContentMatrix](../../src/Reading/ContentMatrix.php), [FontTextDecoder](../../src/Reading/FontTextDecoder.php), [ToUnicodeReader](../../src/Reading/ToUnicodeReader.php) | [ReadingSourceTest](../../tests/ReadingSourceTest.php) : contenu Unicode/Form/image du vrai PDF ; [ReadingScenariosTest](../../tests/ReadingScenariosTest.php) : opérateurs apostrophes, restauration, matrices imbriquées, cisaillement/rotation/UserUnit, images non acceptées et bornes avant allocation ; [ReadingReplacementTest](../../tests/ReadingReplacementTest.php) : remplacement avant décodage. |
| 4.4.8 | OBJR, cibles URI/GoTo/structure et espaces distincts des destinations Name/String ; héritage des widgets, valeurs exportées/options, flags, apparences et présence réelle dans Annots/CropBox. Les signatures rapportent un état sans inventer de validation. | [SourceReadSession](../../src/Reading/SourceReadSession.php), [WidgetDeriver](../../src/Forms/WidgetDeriver.php), [AppearanceRenderer](../../src/Forms/AppearanceRenderer.php) | [ReadingScenariosTest](../../tests/ReadingScenariosTest.php) : identité des destinations, widgets absents et CropBox ; [WidgetDeriverTest](../../tests/Forms/WidgetDeriverTest.php) : choix/radio/texte, readonly, texte riche, fichiers, soumission/reset/signature et visibilité. |
| 4.5 et annexe B | Pont JavaScript optionnel propre au document pour champs/événements courants, validation, calcul, formatage et réinitialisation globale. Les champs partagent leurs valeurs par nom, les widgets conservent leurs événements propres. Aucune exécution PHP et aucune émulation complète d'ISO 21757-1. | [ScriptDeriver](../../src/Script/ScriptDeriver.php), [DerivationPolicy](../../src/DerivationPolicy.php) | [ScriptDeriverTest](../../tests/Script/ScriptDeriverTest.php), [harness Node](../../tests/Script/runtime.cjs) : initialisation, actions propres aux widgets, noms partagés, valeurs rejetées, calculs, readonly et reset global. |
| 4.6 | Plans Alternative/Supplement, HTML/MathML/images incorporés et propriété explicite des ressources ; les types des références URL sont déduits des extensions de leur chemin. CSS, SVG, JavaScript et références distantes suivent des politiques distinctes. Les suppléments head conservent les métadonnées sûres, jamais refresh. | [AssociationPlan](../../src/AssociationPlan.php), [AssetCollection](../../src/AssetCollection.php), [DerivationSession](../../src/DerivationSession.php), [SafetyFilter](../../src/Html/SafetyFilter.php) | [HtmlDeriverTest](../../tests/HtmlDeriverTest.php) : Alternative/Supplement ; [AdvancedDerivationTest](../../tests/AdvancedDerivationTest.php) : head, dimensions SVG et identifiants dupliqués ; [HtmlBoundaryTest](../../tests/HtmlBoundaryTest.php) : ressources head et CSS ; [ReadingScenariosTest](../../tests/ReadingScenariosTest.php) : types d'URL et politique des URL de fichiers locaux. |
| Annexe A | Validation URL/CSS/fragments, échappement, refus des entités/DTD, budgets cumulatifs parcours/sortie/ressources, cycles et contrôles avant expansion. | [SafetyFilter](../../src/Html/SafetyFilter.php), [Serializer](../../src/Html/Serializer.php), [ReadingBudget](../../src/Reading/ReadingBudget.php) | [HtmlBoundaryTest](../../tests/HtmlBoundaryTest.php) : fragments, CSS masqué, expansion des entités, cycles et fermeture de balises brutes ; [ReadingScenariosTest](../../tests/ReadingScenariosTest.php) : destinations cycliques, arbres partagés, XMP UTF-16 et budgets des copies/expansions Unicode. |
| Annexe C | `PdfToHtml` sollicite un fournisseur injecté pour une entrée chiffrée et exige des permissions authentifiées ; la dérivation émet ce contexte. Déchiffrement et application des permissions appartiennent au fournisseur et à l'application. | [PdfToHtml](../../src/PdfToHtml.php), [AuthenticationProvider](../../src/AuthenticationProvider.php), [AuthenticatedPermissions](../../src/Model/AuthenticatedPermissions.php) | [HtmlDeriverTest](../../tests/HtmlDeriverTest.php) : `testEncryptedPermissionContextAndSessionReuse` ; le fournisseur est une frontière d'intégration, pas un backend cryptographique intégré. |
| Annexe D | Ordre de lecture, langue, alternatives, labels de formulaires et relations de tableaux passent par les mécanismes précédents. La revue sémantique et les technologies d'assistance restent nécessaires. | [StructureProperties](../../src/Model/StructureProperties.php), [WidgetDeriver](../../src/Forms/WidgetDeriver.php), [AttributeDeriver](../../src/Attributes/AttributeDeriver.php) | [AdvancedDerivationTest](../../tests/AdvancedDerivationTest.php) : labels/navigation ; [oracle indépendant](../../tools/validate_derivation.py) : langue, alternatives, identifiants et liens. Aucune certification d'accessibilité n'en est déduite. |

Pour le corps numéroté, la page physique correspond à la page imprimée plus six.
L'inventaire source précise les intervalles physiques.

Le filtre MathML admet **192 noms d'éléments** issus des modules RelaxNG W3C
MathML 3, leurs 129 noms d'attributs de schéma et les ajouts globaux/Core acceptés
explicitement. Ce filtre de vocabulaire/sécurité ne valide pas chaque contrainte
grammaticale MathML. Les MathML de présentation, de contenu et élémentaire sont
conservés lorsqu'ils sont acceptés ; Content MathML et `mstack` ne sont pas
obsolètes, mais leur rendu natif par le navigateur n'est pas promis. Le vocabulaire
hors du sous-ensemble Core accepté rapporte `extended-mathml-vocabulary`. Aucun
système de calcul formel ni polyfill de rendu n'est fourni. Les tests de namespaces
et d'intégration HTML d'[HtmlBoundaryTest](../../tests/HtmlBoundaryTest.php)
couvrent aussi faux noms MathML, XML étranger et HTML incorporé. Le XML étranger
SVG/OpenMath reste hors de ce profil.

## Frontières d'ingestion des fichiers

- Le lecteur natif accepte la syntaxe PDF 1.0–2.0 et les chaînes UTF-8 PDF 2.0.
  Il refuse encore les entrées chiffrées, xref hybrides, filtres non implémentés et
  syntaxes endommagées. Un `AuthenticationProvider` fournit un modèle externe
  authentifié/déchiffré ; le package n'intègre pas d'authentification cryptographique.
- L'entrée doit posséder un arbre de structure exploitable. Aucun OCR, balisage
  inféré ou reconstruction géométrique de l'ordre de lecture n'est effectué.
  MCID, parents, cycles, présence sur les pages et identité des namespaces sont
  vérifiés explicitement. Les associations inverses ParentTree ne sont pas
  contrôlées.
- Le texte utilise les codespaces ToUnicode incorporés, bfchar/bfrange et
  l'héritage UseCMap par flux incorporé, ou les encodages simples connus/Differences
  dont la correspondance de glyphes est non ambiguë. Les CMaps externes nommées
  et les fontes composites sans collection ToUnicode exploitable ne sont pas
  résolues implicitement. Le texte non accepté devient un nœud graphique/diagnostic
  explicite ; les correspondances mal formées sont refusées. Reconnaître un vecteur
  d'encodage ne fournit pas toutes les correspondances de secours des glyphes,
  notamment pour Symbol, ZapfDingbats et MacExpert.
- Les images inline `BI…ID…EI` sont refusées explicitement, sans recherche
  heuristique. L'extraction accepte les JPEG DeviceGray/DeviceRGB et les échantillons
  décodés huit bits acceptés, sans transformations Mask/SMask/Decode. Les autres
  codecs, masques, espaces couleur et profondeurs ne sont pas décodés nativement ;
  les nœuds `unsupported-image` dont les dimensions logiques sont conservées
  deviennent des placeholders. Étendre le lecteur natif ou fournir un autre
  adaptateur source est nécessaire pour
  retrouver leurs pixels.
- Les transformations affines donnent les longueurs logiques des côtés, pas les
  dimensions d'échantillonnage ni une reproduction CSS complète du positionnement
  et du clipping PDF. Les matrices dégénérées/non finies et les réutilisations
  Form/image ambiguës provoquent un refus ou un nœud unsupported-image explicite.
  `ContentRenderer`/`AppearanceRenderer` sont des ports de rendu explicites, mais
  ne retrouvent ni géométrie ni état abandonnés par l'adaptateur natif. Le rendu
  complet des apparences/groupes à partir de fichiers n'est pas fourni.

## Profil sûr et modes activés

La section 4.6.1 et l'annexe A, pages physiques 62 et 68, autorisent explicitement
des restrictions de sécurité sur le contenu associé. Les politiques optionnelles
suivantes sont des choix de sécurité volontaires. Elles ne corrigent pas les
lacunes techniques recensées ci-dessus.

La `DerivationPolicy` par défaut désactive `allowActiveContent` et
`allowRemoteResources`, conserve `preferVisualFigures=false` et produit le contenu
sémantique des figures. La dérivation PHP native n'exécute jamais de script et
ne récupère pas d'URL. Le HTML/MathML incorporé et les ressources raster acceptés,
après filtrage, peuvent néanmoins être dérivés.

Activer `allowActiveContent` exporte volontairement les scripts/runtime considérés
fiables et autorise le CSS/SVG importé restreint ; cela ne constitue ni un sandbox
navigateur ni une preuve de sûreté des scripts. `allowRemoteResources` permet
séparément les URL de ressources en sortie, que le navigateur destinataire peut
récupérer. La bibliothèque PHP ne les récupère pas. Aucune option n'implémente
l'intégralité de l'API JavaScript Acrobat/ISO 21757-1.

`ScriptAssetEmitter` externalise les sources des scripts activés dans
`pdf-script-<sha256>.js`, conservant leurs octets JavaScript exacts sans ambiguïté
HTML de fermeture de script. L'application doit écrire chaque ressource à côté du
HTML ou adapter les URL de manière cohérente. C'est un conditionnement, pas un
assainissement des scripts. [HtmlBoundaryTest](../../tests/HtmlBoundaryTest.php)
exerce les séquences de fermeture de script ; le profil sûr par défaut n'émet
aucun runtime de script.

`preferVisualFigures=true` demande un rendu au moteur fourni, sans l'installer ni
le lancer. Les contrôles readonly sans équivalent HTML natif signalent la nécessité
d'une application/runtime d'exécution. Soumission, confiance et application des
permissions restent des responsabilités de l'application.

Un HTML accessible demande encore une revue de l'auteur, de l'ordre de lecture,
des alternatives textuelles, des formulaires et des technologies d'assistance
réelles. Aucune certification générale ISO, WTPDF, PDF/UA ou WCAG n'est annoncée.

## Preuves reproductibles

La CI exécute PHP 8.3–8.5, PHPUnit, l'analyse statique et le formatage. Elle exécute
également :

1. [validate_reference.py](../../tools/validate_reference.py) : nom original,
   900894 octets, SHA-256, identité CC-BY-4.0 et 74 pages via un lecteur indépendant.
2. [build_reading_fixture.py --check](../../tools/build_reading_fixture.py) : recréer
   la fixture PDF 2.0 originale MIT avec pypdf 6.20.1 verrouillé, puis comparer ses
   octets et son manifeste. Aucun writer PHP ne construit cette entrée.
3. [validate_derivation.py](../../tools/validate_derivation.py) : examiner le PDF
   indépendamment avec pypdf, exécuter la conversion PHP et analyser le vrai résultat
   avec le parseur HTML5 d'html5lib. Contrôler Unicode, titre/langue, formulaires,
   liens, identifiants uniques, alternatives et empreintes des ressources, ainsi
   que six transformations sémantiques synthétiques. Vérifier aussi les métriques
   des polices simples PDF 2.0, les descripteurs indirects et les ressources de
   police de l'apparence AcroForm, indépendamment de l'extraction textuelle.
4. [runtime.cjs](../../tests/Script/runtime.cjs) : exécuter le pont optionnel dans
   un harness Node borné, avec opérations courantes des champs, sans navigateur
   ni réseau.

Le candidat initial 0.1.0 passe **192 tests / 592 assertions sur chaque version
PHP**, PHPStan niveau 8, les contrôles PSR-12 sur 78 fichiers PHP et les contrôles
Composer stricts, de plateforme et d'avis de sécurité. Ces nombres identifient
le candidat vérifié ; la suite exécutable fait foi pour les évolutions suivantes.

L'entrée porte l'empreinte SHA-256
`53b69e6ab8bf438c5fd896990ce26e1fbd0eb8a8a475c4a1bbd9e8e90fab23c0`.
Son image d'un pixel est peinte sur 20 points PDF : la lecture conserve 20 × 20
points et la dérivation produit **27 × 27 pixels CSS** après arrondi, au lieu de
confondre échantillonnage et taille physique. Les tests matriciels couvrent aussi
q/Q, Form imbriqués, rotation, cisaillement et UserUnit.

La documentation centrale présente PDF d'entrée, toutes les pages rendues, PHP
exécutable, HTML/JSON réels et provenance exacte de la version. Le parseur HTML5 et
ces tests apportent des preuves sur les cas exercés ; ils ne constituent ni un
validateur universel HTML/CSS/ARIA ni une certification automatique d'accessibilité.

## Adaptations HTML et de sécurité documentées

La référence et les règles actuelles d'écriture HTML ne s'accordent pas sur tous
les cas. Ces décisions sont des adaptations explicites, accompagnées de
diagnostics, sans prétendre reproduire à l'identique un balisage incompatible :

| Référence, page physique du PDF | Adaptation |
| --- | --- |
| Tableau 1, [page 21](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=21) : RB devient `rb` | Produire `span`, conserver `data-pdf-se-type="RB"` et rapporter `obsolete-ruby-base`. WHATWG classe `rb`/`rtc` comme obsolètes ; les fragments importés qui les utilisent sont réparés avec diagnostics. |
| Widgets, [pages 59–60](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=59) : texte riche RV | Conserver le texte brut avec un diagnostic. Un `input` HTML n'a pas d'enfant et `textarea` ne peut pas contenir de balisage riche. |
| Flags des widgets, [pages 59–60](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=59) : ReadOnly | Utiliser `readonly` natif lorsque HTML le prévoit. Les autres contrôles conservent `data-pdf-readonly` et signalent la nécessité d'une application/runtime d'exécution ; les seules métadonnées ARIA ne bloquent pas l'interaction. Voir `testReadOnlyChoiceUsesAnExplicitBridgeRatherThanInvalidReadonly` dans les tests des widgets. |
| 4.4.7.3, [page 55](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=55) : Alt du contenu marqué | Produire `span role="img" aria-label="…"` plutôt qu'un `span[alt]` invalide, en traitant la séquence décrite comme un groupe atomique nommé. Signaler l'adaptation et vérifier son sens avec les technologies d'assistance. |
| Attributs de tableau, [page 45](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=45) : Scope Both | Omettre le `scope="both"` non accepté avec diagnostic ; conserver les associations Headers explicites lorsqu'elles sont fournies. |
| Widgets, [page 59](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=59) : valeur FileSelect | Ne pas préremplir un sélecteur de fichier du navigateur ; omettre la valeur PDF et signaler cette limite. |
| 4.6 et annexe A, [pages 65–68](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=65) : contenu associé | Scripts, feuilles de style/SVG importés et métadonnées actives demandent une politique explicite. Le CSS importé reste restreint : imports, déclarations portant des URL et expressions sont filtrés. Les métadonnées HTTP-equivalent telles que refresh ne sont jamais importées. |

Références HTML : [éléments obsolètes](https://html.spec.whatwg.org/multipage/obsolete.html#non-conforming-features),
[champs fichier](https://html.spec.whatwg.org/multipage/input.html#file-upload-state-(type=file)),
[scope des tableaux](https://html.spec.whatwg.org/multipage/tables.html#attr-th-scope)
et [règles ARIA de nommage](https://www.w3.org/TR/html-aria/#el-span).
Références MathML : [modules RelaxNG officiels](https://www.w3.org/Math/RelaxNG/mathml3/),
[balisage de présentation](https://www.w3.org/TR/MathML3/chapter3.html),
[balisage de contenu](https://www.w3.org/TR/MathML3/chapter4.html) et
[MathML Core](https://www.w3.org/TR/mathml-core/).
Lire la [sécurité](security.md) avant d'autoriser le contenu actif.
