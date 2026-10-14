# Référence Deriving HTML from PDF

[English](../reference.md) · [Français](reference.md)

La source est **Deriving HTML from PDF, version 1.2, septembre 2026**, publiée par
PDF Association sous [CC-BY-4.0](https://creativecommons.org/licenses/by/4.0/).
Le PDF original compte **74 pages physiques** ; son nom et ses octets sont conservés.

[Ouvrir l'original](../../resources/reference-documents/Deriving_HTML_from_PDF_1-2.pdf)
· [Consulter l'inventaire et l'empreinte](../../resources/reference.json).

Le corps numéroté commence à la page physique 7 (page imprimée 1), après six pages
préliminaires. Les annexes sécurité, ECMAScript, fichiers chiffrés et accessibilité
font partie du périmètre conservé. L'original reste la référence ; les explications
du projet ne remplacent pas ses formulations normatives.

La [couverture](coverage.md) distingue règles du modèle, fichiers acceptés et
responsabilités du runtime. Conserver la source ne signifie pas accepter toute
conversion ou fonctionnalité de navigateur. L'original reste hors des archives Composer.

Le [registre des clauses](requirements.md) relie les règles des pages physiques
aux preuves exécutables et distingue comportements natifs, politiques,
adaptations HTML et ports applicatifs. La frontière d'authentification utilise
l'`EncryptedPdfException` typée de pdf-reader ; les preuves de rendu combinent
des PDF isolés analysés indépendamment et de vrais pixels Poppler.

Les règles HTML/ARIA actuelles et le vocabulaire MathML W3C complètent les choix
d'intégration, sans remplacer la référence PDF Association. Le
[guide de couverture](coverage.md#adaptations-html-et-de-securite-documentees)
relie les sources HTML primaires, les schémas officiels MathML 3 et MathML Core,
et consigne les adaptations lorsqu'une correspondance littérale contredit les
règles du navigateur.
