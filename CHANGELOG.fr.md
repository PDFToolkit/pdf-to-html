# Versions et changements

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

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
