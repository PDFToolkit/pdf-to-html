# Sécurité du HTML dérivé

[English](../security.md) · [Français](security.md)

## Distinguer confiance dans le PDF et dans le navigateur

Un PDF peut contenir HTML/CSS, fichiers associés, URL dangereuses, scripts et
actions de formulaire. Réussir l'analyse ne rend pas ces valeurs sûres dans un
navigateur. Conserver les budgets cumulatifs du lecteur et de la dérivation et
examiner les diagnostics avant publication. Ne pas servir des octets associés
comme contenu exécutable sur la seule foi de leur type HTML ou JavaScript déclaré.

## Choisir des politiques d'exécution et de ressources explicites

Le traitement par défaut n'exécute aucun script, n'effectue pas de requête réseau
et ne lit pas de nom de fichier arbitraire. Les URL sont des valeurs, pas des
requêtes. Résoudre les ressources relatives dans le stockage choisi par
l'application ; ne pas concaténer un nom source non fiable à un dossier de sortie.
Une entrée chiffrée nécessite une authentification réelle et les permissions
d'extraction plutôt qu'un indicateur de permission fabriqué.

Une option volontaire d'export des scripts reste distincte de leur exécution et
d'un runtime Acrobat. Événements navigateur et soumissions demandent une revue de
l'application. Le profil sûr restreint les mécanismes actifs de la référence.

Les scripts activés sont renvoyés comme ressources JavaScript externes par
`ScriptAssetEmitter`. Cela évite les ambiguïtés d'analyse du texte brut HTML sans
modifier la grammaire du script et sans rendre son code sûr. Conserver ou servir
ces ressources relève explicitement de l'application, avec son origine et sa
politique de sécurité du contenu.

## Valider avant sérialisation

Échapper textes et attributs. Valider noms d'éléments et d'attributs, schémas d'URL,
déclarations CSS, identifiants et types des fichiers associés. Les contrôles et
un `javascript:` avec une casse mélangée restent dangereux. CSS peut récupérer des
ressources ou masquer le contenu et demande sa propre politique. SVG/MathML et
HTML importé nécessitent une validation de structure.

Le filtre MathML distingue namespaces et points d'intégration HTML. Noms MathML
inconnus, usurpations de namespace, attributs actifs et XML étranger non accepté
sont refusés ou diagnostiqués, y compris avec la politique de contenu fiable.
Conserver le MathML de contenu n'implique pas que le navigateur sache l'afficher
ou l'évaluer.

Prévisualiser une conversion non fiable dans une origine distincte ou une frame
sandboxée. Cette frontière complète la validation. Ne pas combiner `allow-scripts`
et `allow-same-origin` pour une sortie non fiable.

## Vérifier et signaler

La suite exécutable vérifie sortie sémantique, valeurs hostiles et bornes d'entrée.
Ces contrôles apportent des preuves sur les cas acceptés et ne prouvent pas
l'absence éternelle de vulnérabilité. Signaler les problèmes en privé selon la
[politique de sécurité](../../SECURITY.fr.md).
