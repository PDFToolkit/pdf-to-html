# Deriving HTML from PDF : couverture et frontières

[English](../coverage.md) · [Français](coverage.md)

La version **0.7.0** implémente les mécanismes de dérivation de la référence
conservée par une interprétation source native, des politiques explicites et des
ports propres à l'application. Le [registre des clauses](requirements.md) associe
chaque règle numérotée à sa décision et à des preuves concrètes. La couverture
ne signifie ni acceptation universelle des PDF, ni reproduction des mises en page
par le navigateur, ni certification d'accessibilité.

ID source et Headers Table sont résolus vers les cibles HTML finales après les
redéfinitions explicites et alternatives associées ; identité binaire et Headers
HTML de l'auteur restent distincts. Les liens structurés visent l'ID final du
remplacement dans les deux ordres de lecture ; une ancre reste pour les alternatives
sans élément. Les identifiants de navigation désignent le véritable parent du contenu,
dont remplacements et widgets, plutôt qu'un marqueur vide sans rapport.

## Périmètre de la référence

La référence est **Deriving HTML from PDF 1.2**, septembre 2026, spécification
d'usage PDF Association pour les fichiers ISO 32000-2 bien balisés. Ses 74 pages
physiques et octets originaux restent dans `resources/reference-documents`.
`resources/reference.json` précise son empreinte et son inventaire.
Ajouter six au numéro de page imprimée donne la page physique du PDF.

L'algorithme dérive le contenu logique. Il ne définit ni conversion HTML vers PDF,
ni OCR, ni balisage inféré, ni reconstruction de mises en page arbitraires.

## Trois périmètres à distinguer

1. **Modèle sémantique** : structure, texte Unicode, attributs, widgets,
   associations et programmes graphiques originaux immuables peuvent aussi être
   fournis directement.
2. **Interprétation native de fichiers** : l'adaptateur retrouve ces informations
   dans la syntaxe et les encodages PDF acceptés, avec budgets et contrôles de graphes.
3. **Intégration de l'application** : moteurs de rendu, authentification,
   hébergement des ressources, politiques navigateur, soumissions et application
   des permissions restent des capacités explicites.

## Mécanismes et couverture de régression

Les sources à clé publique du lecteur 0.6 utilisent le même contrat de masque
effectif. `tools/validate_public_key_authentication.py` produit indépendamment
des sources balisées CMS et contrôle leur HTML5, le bit 2, les deux emplacements
MAC, les mauvais certificats et la réutilisation du convertisseur. Dix sources s3
supplémentaires vérifient que les bits réservés n’accordent pas de droits granulaires
ultérieurs ; les chemins natif et fournisseur explicite conservent le masque
normalisé de Reader 0.6 dans le HTML analysé selon HTML5.

L’authentification source utilise aussi la vérification MAC native du lecteur
0.4. `SourceIntegrityTest` et `tools/validate_source_integrity.py` couvrent les
sources balisées R6 autonome/attachée et R7 autonome, les deux voies
d’authentification, l’identité exacte de la source, la conservation de la
politique exigée et des droits effectifs utilisateur/propriétaire. Cette
extension de l’annexe C n’authentifie pas le HTML produit.

| Référence et pages physiques | Comportement | Preuves concrètes |
| --- | --- | --- |
| 4.1–4.3.1, 11–16 | Associations inverses ParentTree contrôlées avec les parents directs, MCID et présences sur les pages. Permissive rapporte les incohérences, Strict les refuse. Cycles/budgets restent des erreurs. | [ParentTreeReadingTest](../../tests/Reading/ParentTreeReadingTest.php) : pages, annotations, MCR Form, entrées inverses seules, clés, cycles et budgets. |
| 4.3.5/4.3.6/4.3.7, 23–52 | Labels indirects/imbriqués, labels contextuels, classes/identifiants, ActualText, Alt/E, priorités des propriétaires, mappings Layout/listes/tableaux et réparation HTML. CSS explicite prioritaire sur les valeurs par défaut ; Placement ne redéfinit pas les labels de listes. Headers et ID partagent la même identité binaire. | [LabelDerivationTest](../../tests/LabelDerivationTest.php), [StructureAttributePrecedenceTest](../../tests/StructureAttributePrecedenceTest.php), [TableHeadersReadingTest](../../tests/Reading/TableHeadersReadingTest.php), [HtmlBoundaryTest](../../tests/HtmlBoundaryTest.php). |
| 4.3.5.10, 36–37 | Premier URI/GoTo des séquences, priorité SD, destinations Name/String distinctes, ID binaires et vues validées. Seule la navigation sélectionnée est traitée. | [LinkActionsReadingTest](../../tests/Reading/LinkActionsReadingTest.php) : chaînes, actions partagées, cycles, profondeur, identifiants et appartenance à l'arbre réel. |
| 4.4.2, 53 | ToUnicode incorporé et correspondances de secours des listes Adobe, dont Symbol, ZapfDingbats et MacExpert, noms multi-scalaires et Unicode supplémentaire. | [GlyphNameDecoderTest](../../tests/Reading/GlyphNameDecoderTest.php) : tous les enregistrements épinglés, vrais vecteurs de fontes, Differences et budgets d'expansion. |
| 4.4.3, 53–54 | Frontières des données inline analysées par longueurs/terminateurs propres au codec, dimensions logiques conservées. Conversion disponible : image ; conversion des pixels indisponible : placeholder. | [Tests inline](../../tests/Reading), [fixture/oracle inline indépendant](../../tools/validate_inline_derivation.py), [ReadingScenariosTest](../../tests/ReadingScenariosTest.php). |
| 4.4/4.4.1/4.4.4/4.4.5, 52–54 | Instructions, opérandes, état, ressources, plages sélectionnées et appels Form originaux conservés. L'export isolé garde le clipping du texte hérité, la transparence/le contenu optionnel de page et la version PDF source ; artefacts imbriqués et peintures non sélectionnées omis. L'application fournit la rasterisation. | [GraphicProgramReadingTest](../../tests/GraphicProgramReadingTest.php), [GraphicPdfExporterTest](../../tests/Rendering/GraphicPdfExporterTest.php), [GraphicExporterContextTest](../../tests/Rendering/GraphicExporterContextTest.php), [oracle de pixels](../../tools/validate_graphics.py), [comparaisons des pixels de contexte](../../tools/validate_graphics_context.py). |
| 4.4.8.3.1, 56–61 | ResetForm lit `/DV`, `/Fields`, noms parents et exclusion. SubmitForm HTML lit noms de mapping et flags d'export. Leurs permissions sont distinctes des scripts PDF. | [FormActionsReadingTest](../../tests/Reading/FormActionsReadingTest.php), [FormActionsTest](../../tests/Forms/FormActionsTest.php), [runtime Node](../../tests/Script/runtime.cjs). |
| 4.5/annexe B, 61–62/69–71 | Valeurs des champs partagées ; événements des widgets liés aux identifiants HTML finaux. Scripts du document/des pages et validation/calcul/formatage restent propres au document dérivé. | [ScriptDeriverTest](../../tests/Script/ScriptDeriverTest.php), [runtime Node](../../tests/Script/runtime.cjs) : formulaires imbriqués, homonymes étrangers, contrôles renommés et identifiants ressemblant à des prototypes. |
| 4.6, 62–67 | Alternative/Supplement applicable sélectionné avant décodage du contenu remplacé, même sur une page partagée. Les fichiers bloqués conservent l'original. Le Supplement HTML du catalogue ignore les autres contenus ; EF prévaut sur FS/URL. Ressources comptabilisées une fois entre les passes. | [AssociatedFilesReadingTest](../../tests/Reading/AssociatedFilesReadingTest.php), [ReadingReplacementTest](../../tests/ReadingReplacementTest.php). |

Ces tests complètent les contrôles initiaux du HTML5 sémantique et de la fixture
PDF réelle. Le registre couvre les autres mappings, propriétés, propriétaires et
annexes. Ce sont des décisions exécutables, sans certification générale implicite.

## Ce que complète la version 0.6.0

Les propriétés CSS personnalisées conservent leur casse ; les noms Unicode légaux
des UserProperties restent distincts. Les filtres partagés des labels de liste
n’ajoutent plus de spécificité face au CSS auteur ultérieur. Les listes de
description déplacent les listes enfants orphelines hors de dl en conservant ordre,
destinations et imbrications légitimes dans dd. La sélection Supplement des scripts
HTML précède désormais le décodage des descendants ignorés.

Les propriétés de contenu marqué se combinent même si ActualText ou le texte
d’expansion est vide. Une instruction d’affichage de texte partiellement décodée
utilise un seul repli visuel, sans dupliquer les fragments déjà décodés. Les
artefacts des Form imbriqués restent exclus, y compris dans les programmes enfants
ne contenant que des artefacts. Les apparences source des widgets sélectionnent
les états AP normal/appuyé/survol, les ajustent au Rect et réutilisent le renderer
explicite via `ContentAppearanceRenderer`.

`HighlightingMode` traduit N/I/O/P/T en CSS de la bibliothèque en conservant
sémantique native des contrôles et priorité des styles auteur. N est neutre,
I inverse, O inverse la bordure, P/T utilisent l’apparence appuyée si fournie ou
un décalage d’un pixel. R fournit l’apparence au survol. Ces mappings d’interaction
navigateur ne reproduisent pas arbitrairement un viewer PDF pixel pour pixel.

Les chaînes JavaScript Next bornées conservent l’ordre source. Les scripts A des
widgets s’exécutent à l’activation sous la politique des scripts ; CO AcroForm
devient `TaggedDocument::$calculationOrder` et ordonne les événements de calcul.
Cela reste un pont champs/événements délimité, pas un runtime Acrobat complet.

La nouvelle [capacité de signature](signatures.md) valide les octets PDF originaux
sur demande explicite et présente le résultat dans un popover natif. L’adaptateur
fourni rapporte `integrity-verified` avec un certificat choisi, sans décision PKI
ou de permission des révisions. Sans validation, un champ signé reste
`not-validated` ; un champ vide est `unsigned`.

## Fichiers acceptés et ports d'extension

Le lecteur natif accepte la syntaxe PDF 1.0–2.0 et les chaînes UTF-8 PDF 2.0 dans
son périmètre de références croisées/filtres. Syntaxes endommagées et mécanismes
non pris en charge échouent explicitement. Les entrées chiffrées peuvent utiliser
l’opération Standard native explicite, l’authentification par clé publique du
lecteur 0.6 ou le port d’authentification distinct. L’adaptateur conserve les
permissions effectives ; un adaptateur personnalisé ne peut ni supprimer ni élever un masque natif vérifié. Un mot de
passe incorrect échoue avant dérivation. Voir l’[authentification](authentication.md)
et l’[oracle indépendant qpdf/pypdf/HTML5](../../tools/validate_authentication.py).

Unicode utilise les codespaces ToUnicode incorporés, bfchar/bfrange et l'héritage
UseCMap incorporé, ou les encodages simples/Differences reconnus. Les CMaps
externes nommées ne sont pas chargées implicitement. Les glyphes inconnus
conservent codes/programme originaux pour un rendu visuel et produisent des
diagnostics ; les correspondances mal formées restent des erreurs.

L'interprétation inline possède des frontières vérifiées pour ses encodages
intégrés. `InlineImageBoundaryProvider` peut fournir une frontière exacte pour
un codec supplémentaire. Il doit utiliser le budget fourni et placer le curseur
à la fin du contenu encodé ; le délimiteur est vérifié. La bibliothèque ne
recherche pas un sous-texte `EI` commode dans des données binaires arbitraires.

L’extraction raster native utilise aussi les plans exacts de Reader. Le [contrat des images décodées](image-codecs.md) précise couleur/ICC/alpha JPX, ColorTransform JPEG et cas non représentables explicites.
Masques, autres espaces couleur et profondeurs peuvent utiliser un placeholder
ou la capacité explicite de rendu, dont les programmes/ressources originaux
restent disponibles. `GraphicPdfExporter` reconstruit un PDF isolé et
`RasterContentRenderer` le transmet à `GraphicsRasterizer`. Un profil visuel sans
moteur requis échoue explicitement. Aucun binaire de rendu n'est choisi ni lancé
implicitement.
Les programmes de page utilisent la CropBox physique par défaut ; les références
d'objet Form autonomes gardent leur XObject et ses limites transformées, avec
clipping BBox, Matrix, Group et OC. Une BBox de structure ou un viewport explicite
en coordonnées de page peut remplacer les limites de sortie. Chaque matrice
est appliquée une fois, sans cadrage par les pixels peints. Le [guide graphique](graphics.md) présente le contexte
conservé et l'exemple d'intégration.

## Placement MathML et rendu navigateur

La version 0.5.1 traite l’[issue #1](https://github.com/PDFToolkit/pdf-to-html/issues/1).
Le CSS `display:inline` ou `display:block` dérivé automatiquement ne doit pas
remplacer la mise en page mathématique native des éléments MathML. Sur une
racine `math`, Placement Block devient `display="block"` ; Inline ne nécessite
aucune déclaration display générée. Les descendants MathML gardent leur mise
en page native. Les classes Layout partagées conservent leurs mappings display
ordinaires pour les éléments HTML sans les appliquer au MathML.

Cela concerne les déclarations générées depuis Layout, y compris le display
implicite nécessaire aux espacements Layout. Le CSS explicite de l’auteur n’est
pas réécrit. Un attribut display MathML fourni par son propriétaire namespace ou
conservé dans un fragment reste également prioritaire ; Placement Block ne le
remplace pas. Ces règles ne rendent pas tout CSS explicite compatible avec la
mise en page mathématique.

La référence conservée mappe directement les types structurels MathML
(§4.3.2.3, pages physiques 17–18), distingue les wrappers Formula (§4.3.5.7,
pages 31–32) et traite Layout avant HTML/CSS et les attributs du namespace MathML
(§4.3.7.1, page 43). La table 4 (§4.3.7.6, page 47) donne le mapping générique
de Placement vers CSS ; elle ne prescrit pas un attribut MathML `display`.
L’adaptation propre au MathML utilise le
[modèle display de MathML Core](https://www.w3.org/TR/mathml-core/#css-extensions-for-math-layout)
et sa [feuille de style native](https://www.w3.org/TR/mathml-core/#user-agent-stylesheet)
pour conserver la mise en page mathématique. Le MathML incorporé reste soumis
au §4.6.4.7, pages 66–67, et à la politique existante des fragments.

[validate_mathml.cjs](../../tools/validate_mathml.cjs) est le point d’entrée de
validation indépendante dans les navigateurs. Ses rapports doivent identifier
les versions des navigateurs et les cas de mise en page exercés ; la réussite
des contrôles ne prouve pas une conformité complète de la grammaire MathML,
du rendu ou de l’accessibilité. Aucun polyfill ou runtime distant n’est ajouté
à la sortie dérivée.

Depuis un checkout dont les dépendances Composer sont installées, installer
explicitement les dépendances de validation verrouillées et les moteurs de
navigateur, puis exporter les cas réellement dérivés par PHP :

```sh
npm ci --prefix tools
node tools/node_modules/playwright/cli.js install --with-deps chromium firefox
mkdir -p _build/mathml
php tests/export-mathml-cases.php > _build/mathml/cases.json
node tools/validate_mathml.cjs _build/mathml/cases.json _build/mathml/browsers
```

Conserver `_build/mathml/browsers/report.json` avec les captures PNG. Le rapport
consigne les versions des navigateurs, le display calculé et la géométrie des
exposants/fractions pour les cas générés ; son résultat `passed` concerne cette
exécution. L’analyse HTML et les contrôles PHPUnit restent des preuves séparées.

## Politiques et frontières du navigateur

Les [politiques](policies.md) décrivent le profil commun source/sortie, les actions
natives de formulaire, l'export des scripts PDF, les ressources distantes et le
rendu. La section 4.6.1/l'annexe A permettent des restrictions de sécurité sur le
contenu associé. Ces choix volontaires sont distincts des erreurs d'analyse.

Le profil par défaut désactive contenu PDF actif, ressources distantes et
réinitialisations/soumissions. L'application peut activer les actions de
formulaire propres à la bibliothèque sans exporter l'ECMAScript PDF. Runtime
généré et scripts fiables activés deviennent des ressources JavaScript externes ;
écrire toutes les ressources renvoyées ou adapter leurs URL. Aucun script ne
s'exécute en PHP et aucune URL ne provoque de requête réseau PHP implicite.

Le filtre MathML admet **192 noms d'éléments** issus des modules RelaxNG W3C
MathML 3, leurs 129 noms d'attributs de schéma et les ajouts globaux/Core acceptés.
Ce filtre de vocabulaire/sécurité ne valide pas chaque contrainte grammaticale.
Les MathML de présentation, de contenu et élémentaire peuvent être conservés.
Le vocabulaire étendu produit `extended-mathml-vocabulary` ; rendu navigateur,
calcul mathématique et polyfills appartiennent à l'application. Le XML étranger
SVG/OpenMath reste hors de ce profil MathML.

L'émulation d'Acrobat/ISO 21757-1, la reproduction de mises en page arbitraires,
les certifications automatiques WTPDF/PDF/UA/WCAG et l'application des permissions
par le navigateur ne sont pas promises. La référence laisse les détails du
rendu, de l'authentification et du viewer aux implémentations ; le package expose
ces capacités explicitement.

## Preuves reproductibles

La CI vérifie PHP 8.3–8.5, PHPUnit, PHPStan, PSR-12 et Composer, ainsi que :

1. [validate_reference.py](../../tools/validate_reference.py) : nom conservé,
   900894 octets, SHA-256, identité CC-BY-4.0 et 74 pages via un lecteur indépendant.
2. [build_reading_fixture.py --check](../../tools/build_reading_fixture.py) : fixture
   pypdf épinglée et manifeste exacts, construits indépendamment du writer PHP.
3. [validate_derivation.py](../../tools/validate_derivation.py) : vraie conversion,
   inspection indépendante du PDF et analyse HTML5, Unicode, métadonnées, formulaires,
   liens, identifiants, alternatives et empreintes ; transformations sémantiques et métriques.
4. [validate_inline_derivation.py](../../tools/validate_inline_derivation.py) : PDF
   inline indépendant et contrôles des dimensions logiques/d'échantillonnage.
5. [validate_graphics.py](../../tools/validate_graphics.py) : exports isolés analysés
   par pypdf et rendus par Poppler, avec vrais contrôles des positions/couleurs des pixels.
6. [runtime.cjs](../../tests/Script/runtime.cjs) : exécution JavaScript bornée avec
   bindings réels, valeurs partagées, événements de widgets et actions de formulaire.
7. [validate_graphics_context.py](../../tools/validate_graphics_context.py) : pixels
   originaux et exportés pour clipping du texte hérité, groupes de transparence et
   contenu optionnel ; omission des artefacts et conservation de la version PDF.
8. [validate_inline_fax.py](../../tools/validate_inline_fax.py) : données CCITT
   libtiff indépendantes et contrôles Poppler des frontières inline exactes.
9. [validate_page_identifiers.py](../../tools/validate_page_identifiers.py) : contrôles
   HTML5 des parents réels, cibles uniques, navigation et expansion des champs.

10. [validate_attribute_conformance.cjs](../../tools/validate_attribute_conformance.cjs) :
    attributs Unicode, identité des propriétés CSS et cascade partagée dans les navigateurs.
11. [validate_widgets.cjs](../../tools/validate_widgets.cjs) : surbrillance, états
    appuyé/survol et CSS auteur avec JavaScript désactivé dans Chromium et Firefox.
12. [validate_appearances.py](../../tools/validate_appearances.py) : comparaison
    pypdf/Poppler des apparences natives sélectionnées, tailles transformées et artefacts imbriqués.
13. [validate_signatures.py](../../tools/validate_signatures.py) : fixtures PDF/CMS
    indépendantes, révisions couvertes exactes, certificats incorrects et altérations.
14. [validate_signature_popovers.cjs](../../tools/validate_signature_popovers.cjs) :
    explications échappées et activation souris/clavier sans JavaScript PDF.

Les builders des listes de glyphes et fixtures inline épinglées sont également
contrôlés sans modifier leurs octets publiés. Le déclenchement d'authentification
et les cibles finales Alternative/Headers ont des régressions PHPUnit dédiées.

La fixture principale conserve l'empreinte SHA-256
`53b69e6ab8bf438c5fd896990ce26e1fbd0eb8a8a475c4a1bbd9e8e90fab23c0`.
Son image d'un pixel peinte sur 20 points PDF donne **27 × 27 pixels CSS** ;
les dimensions logiques ne deviennent pas celles de l'échantillonnage. Les cas
matriciels couvrent aussi q/Q, Form imbriqués, rotation, cisaillement et UserUnit.

La documentation centrale montre PDF d'entrée, pages rendues, PHP exécutable,
HTML/JSON/ressources réels et provenance exacte de la version. Tests et oracles
valident leurs cas exercés ; les suites publiées font foi pour leurs nombres.

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
| Attributs de tableau, [page 45](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf#page=45) : Scope Both | Conserver `data-pdf-table-scope="Both"`, omettre `scope="both"` non accepté avec diagnostic et garder les Headers explicites. Cette adaptation §4.3.4 conserve la valeur source sans synthétiser de relations ; Row/Column ultérieur remplace la métadonnée. |
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

Les images inline appliquent l’exception EC3 issue 20 (ISO 32000-2, page physique 283) : si le dernier ou seul filtre est ASCIIHexDecode ou ASCII85Decode, les données peuvent suivre immédiatement `ID`. Les autres chaînes conservent le séparateur blanc obligatoire. La liste de filtres normalisée détermine cette exception ; un signe pourcentage dans des données ASCII85 n’est jamais interprété comme un commentaire PDF. Le cadrage du codec détermine toujours la fin des données.

Les [chemins natifs d’image](graphics.md#échantillons-dimage-natifs-et-cadrage-fax) conservent exactement DeviceGray 1/2/4/8/16 et DeviceRGB 8/16 dans PNG. Le cadrage et le décodage fax partagent l’automate Reader ; masques, Decode explicite et autres espaces de couleur gardent leur renderer/substitut explicite. L’oracle indépendant couvre 14 chemins natifs et trois replis, en distinguant les octets 16 bits exacts du rendu d’affichage 8 bits.
