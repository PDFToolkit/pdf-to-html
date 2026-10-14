# Versions et changements

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## 0.2.1 — 2026-10-15

- Exporter les Form XObjects référencés directement comme de vrais XObjects
  isolés, avec leur clipping `/BBox` local, groupe de transparence `/Group` et
  contexte de contenu optionnel. Leurs instructions aplaties dans une page ne
  débordent plus d'une boîte cisaillée et ne modifient plus le mélange du groupe.
- Appliquer la matrice source du Form une seule fois et convertir séparément
  les viewports en coordonnées page avec l'objet immuable `PageTransform`,
  incluant rotation et `UserUnit`. Réutiliser cet objet pour la lecture native.
- Ajouter des fixtures OBJR Form indépendantes et des comparaisons Poppler pour
  le clipping, la transparence, la visibilité et les viewports transformés ;
  actualiser les guides dans les deux langues.

## 0.2.0 — 2026-10-15

- Requiert `pdftoolkit/pdf-reader` **0.2.3 ou ultérieur dans la branche 0.2.x**,
  avec son exception typée pour les entrées chiffrées et son interprétation
  bornée des images inline.
- Vérifie les associations inverses du ParentTree, l'appartenance aux pages,
  annotations et Forms, les identifiants binaires et les Headers des tableaux ;
  résout les cibles de navigation et d'en-têtes après la priorité des IDs HTML
  explicites.
- Sélectionne les remplacements ActualText et Alternative/Supplement applicables
  avant d'interpréter le contenu remplacé. Conserve l'original si la politique
  bloque le remplacement, le décodage différé et les budgets cumulatifs entre
  pages partagées, ressources et actions.
- Interprète les séquences URI/GoTo, les destinations structurelles et les
  espaces distincts Name/String des destinations nommées, avec validation
  explicite des graphes et des vues.
- Complète le repli Unicode par noms de glyphes Adobe pour les fontes simples,
  dont Symbol, ZapfDingbats, MacExpert, Differences et les noms à plusieurs
  scalaires. Conserve les programmes de fontes pour le rendu et diagnostique
  les correspondances indisponibles.
- Dérive les images inline avec frontières de codecs vérifiées et dimensions
  logiques ; ajoute le cadrage borné par mots de code CCITT. Une conversion de
  pixels indisponible conserve un espace réservé aux bonnes dimensions. Un
  codec de frontière supplémentaire nécessite un `InlineImageBoundaryProvider`
  explicite ; aucun `EI` n'est recherché arbitrairement dans les octets binaires.
- Conserve des `GraphicProgram` immuables et ajoute `GraphicPdfExporter` pour
  produire des PDF isolés d'une page avec le contenu sélectionné. Préserve
  ressources, transformations, clipping, avances de texte et appels partiels
  de Forms, en supprimant les dessins étrangers et artefacts. Conserve le groupe
  de transparence `/Group`, `/OCProperties`, la visibilité du contenu optionnel,
  le mode de rendu du texte `/Tr` hérité et la version PDF source. Les budgets de
  ressources, instructions, profondeur et octets sont cumulatifs.
- Ajoute `RasterContentRenderer` et la capacité explicite `GraphicsRasterizer`.
  L'application fournit son moteur ; la bibliothèque ne lance aucun processus
  implicitement. Le profil visuel demandé échoue si son moteur est indisponible.
- Implémente des sélections d'actions ResetForm/SubmitForm immuables : groupes,
  inclusion/exclusion, listes explicitement vides et valeurs PDF `/DV` par défaut.
  Les soumissions HTML acceptées respectent la sélection des champs et de leur
  export. Chaque opération demande son activation par `FormActionPolicy`,
  indépendamment d'ECMAScript PDF ; la sortie par défaut n'exécute ni ces actions
  ni les scripts source.
- Étend les contrôles du runtime propre au document pour validation, calcul,
  formatage et événements de page. Conserve les liaisons aux IDs finaux des
  widgets et isole les contrôles étrangers même lorsque leurs noms HTML sont
  identiques. Toutes les ressources de runtime/scripts retournées doivent être
  conservées avec le HTML ou leurs URL explicitement remappées.
- Place `PDF-Page-X` sur le vrai parent du contenu, y compris les widgets et
  remplacements multipages, et diagnostique les pages sans contenu conservé.
  Entoure le contenu de formulaire avec expansion d'un `abbr` en conservant les
  IDs des champs et les titres explicites ; empêche Placement de redéfinir les
  libellés de listes selon un autre contexte.
- Partage politique et limites de dérivation entre lecture native et sortie.
  Déclenche l'authentification uniquement sur l'exception du lecteur pour une
  entrée chiffrée vérifiée. Un `AuthenticationProvider` injecté fournit source
  authentifiée et permissions, dont le lecteur HTML doit assurer le respect.
- Conserve la référence originale à l'identique et ajoute le registre des
  exigences par clause, des guides bilingues sur politiques/graphiques et des
  exemples exécutables d'export de figure et de reset sûr. Étend les fixtures PDF
  indépendantes, analyses HTML5/pypdf, contrôles de pixels Poppler et scénarios
  Node pour les nouveaux comportements.

Règles natives, adaptations HTML documentées, politiques de sécurité et capacités
de l'application sont distinguées dans les [exigences](docs/fr/requirements.md)
et la [couverture](docs/fr/coverage.md). Rasterisation, authentification
cryptographique et codecs non pris en charge restent des frontières d'intégration
explicites. Cette version ne revendique ni acceptation de tout PDF, ni émulation
complète Acrobat/ISO 21757-1, ni reproduction exacte de la mise en page dans le
navigateur, ni certification automatique d'accessibilité.

## 0.1.2 — 2026-10-14

- Associe les libellés indirects d'un Form à leur widget en conservant la portée
  des Forms imbriqués et les labels HTML explicites.
- Repère les remplacements ActualText structurels avant d'interpréter les pages
  partagées ; conserve texte de remplacement, ancres et validation des graphes.
- Distingue les événements propres aux widgets partageant un champ, avec valeur
  et validation communes et budgets cumulatifs de scripts.
- Relie le runtime aux objets WidgetBinding immuables : les Forms imbriqués gardent
  leur identité et les contrôles HTML homonymes étrangers restent indépendants.
- Applique le titre d'expansion et le display par défaut de LBody avant les
  attributs HTML/CSS explicites, conformément à la section 4.3.7.1.
- Relie directement les deux README à la référence conservée et distingue les
  lacunes techniques restantes des politiques de sécurité et d'intégration.

## 0.1.1 — 2026-10-14

- Complète les métriques des polices simples et leurs descripteurs indirects,
  l'encodage explicite des glyphes Unicode et l'apparence du champ dans le PDF 2.0
  de test produit indépendamment.
- Renforce l'oracle PDF indépendant : il rejette les métriques manquantes et
  l'absence de ressources de police pour l'apparence AcroForm avant de vérifier
  le HTML dérivé.
- Conserve l'API de production et la sortie sémantique ; actualise l'identité du
  fixture et les preuves de couverture dans les deux langues.

## 0.1.0 — 2026-10-14

Première version publique suivant **Deriving HTML from PDF 1.2** de PDF Association.

- Objets source immuables et traitement propre au document, avec lecteur,
  interprétation, dérivation sémantique et propriété des sorties explicites.
- Structure et rôles qualifiés par namespace, propriétés, attributs/classes,
  alternatives textuelles, listes, tableaux, figures, liens, MathML et formulaires.
- Adaptateur borné de PDF balisés utilisant `pdftoolkit/pdf-reader` 0.2.1, avec
  correspondances Unicode incorporées, images acceptées, matrices et annotations.
- Fichiers associés Alternative/Supplement, validation HTML/CSS/URL,
  budgets cumulatifs, diagnostics et ressources explicites.
- Pont JavaScript optionnel champs/pages/document avec scripts externalisés ;
  fournisseurs injectables de rendu graphique/apparences et de source authentifiée.
- Référence originale de 74 pages conservée à l'identique hors archives Composer ;
  documentation anglais/français et exemple PDF vers HTML reproductible.

Validation : **192 tests / 592 assertions sur PHP 8.3, 8.4 et 8.5**, PHPStan niveau 8,
PSR-12 sur 78 fichiers PHP, contrôles Composer/plateforme stricts et audit des
dépendances. Des analyses pypdf/HTML5 indépendantes vérifient la fixture réelle,
la sortie et six cas sémantiques ; un harness Node borné vérifie le runtime optionnel.

Une entrée balisée exploitable est nécessaire. Cette version ne promet ni
conversion de tout PDF, ni reproduction de mise en page, ni émulation complète
Acrobat/ISO 21757-1, ni certification d'accessibilité. Rendu et authentification
cryptographique demandent des intégrations explicites de l'application. Voir la
[couverture](docs/fr/coverage.md) pour les limites exactes d'entrée, de
MathML/navigateur et de contenu actif.
