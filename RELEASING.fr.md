# Publier une version de pdftoolkit/pdf-to-html

[English](RELEASING.md) · [Français](RELEASING.fr.md)

Conserver des commits atomiques cohérents. La première version publique est
0.1.0 ; avant 1.0, une fonctionnalité incrémente la version mineure, une correction
compatible ou une mise à jour documentaire la version corrective.

1. Publier d'abord les dépendances modifiées. Garder des contraintes d'exécution
   stables, sans branche de développement ni commit non publié. Mettre à jour le
   lock depuis des versions publiées.
2. Mettre à jour les deux changelogs et documenter entrées, politiques et limites.
3. Exécuter validation Composer, installation verrouillée, contrôles de plateforme,
   audit, tests, PHPStan, formatage, empreintes et validations HTML/PDF indépendantes.
   Exiger une CI PHP 8.3, 8.4 et 8.5 réussie pour le SHA candidat exact.
4. Vérifier que `vX.Y.Z` n'existe ni localement ni à distance. Créer un tag annoté
   sur ce SHA validé, le pousser et publier la release GitHub avec les notes
   préparées. Ne jamais déplacer un tag publié.
5. Vérifier SHA distant résolu, métadonnées de release et version/référence
   Packagist. Installer cette version stable exacte dans un nouveau consommateur
   avec les dépôts Composer par défaut, sans substitution VCS/path. Exécuter l'exemple.
6. Mettre à jour `PDFToolkit/pdftoolkit.github.io/resources/sources.json` avec la
   version et son commit exact. Construire guides/API bilingues et exemples
   HTML/PDF réels, vérifier le déploiement et publier une version documentaire distincte.

Le core ne doit pas dépendre de ce module optionnel. Ne pas publier des packages
inchangés pour synchroniser leurs numéros. Les PDF source restent inchangés sous
`resources/reference-documents/`, hors des archives Composer.
