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

`FormActionPolicy` autorise reset/soumission propres à la bibliothèque séparément
de l'ECMAScript PDF. Les deux sont désactivés par défaut. Activer reset n'exporte
aucun script PDF et n'active pas la soumission. Les cas PHP/Node contrôlent `/DV`,
sélection/exclusion et NoExport. Vérifier les destinations avant d'activer la
soumission.

L'adaptateur par défaut de `PdfToHtml` utilise la politique du dériveur. Les
fichiers bloqués ne sont pas décodés et ne suppriment pas l'original. Les
politiques source/sortie doivent aussi correspondre lors d'un assemblage séparé.

Un lecteur configuré explicitement peut effectuer l’authentification Standard
native et fournir les permissions utilisateur/propriétaire effectives. Un
identifiant incorrect échoue avant adaptation. Les gestionnaires non acceptés
et mécanismes MAC non acceptés restent des erreurs du lecteur. Le lecteur 0.4
vérifie le MAC source avant l’interprétation du contenu ; `RequirePdfMac` refuse
une entrée non protégée sur les voies native et fournisseur. Ce fournisseur est
une capacité applicative de confiance : son `ReadDocument` authentifié doit
correspondre aux octets exacts et l’adaptateur ne peut ni retirer ni élever ses
permissions effectives. Aucune vérification mathématique de signature CMS,
confiance PKI ou authentification du HTML produit n’est impliquée. Voir
l’[authentification](authentication.md) et [SourceIntegrityTest](../../tests/SourceIntegrityTest.php).

Seule l’`EncryptedPdfException` typée du lecteur pour `/Encrypt` vérifié déclenche
le port d’authentification distinct. Filtres non acceptés et erreurs d’adaptation ne le déclenchent
pas par la présence d'un mot ressemblant à une mention de chiffrement. Le
fournisseur renvoie des permissions vérifiées, pas un masque supposé.
[AuthenticationBoundaryTest](../../tests/AuthenticationBoundaryTest.php) vérifie
que l'absence/nullité de Encrypt, les erreurs d'interprétation et les échecs du
fournisseur ne sont pas reclassés en demande d'authentification, et refuse les
permissions non vérifiées.

Les scripts activés sont renvoyés comme ressources JavaScript externes par
`ScriptAssetEmitter`. Cela évite les ambiguïtés d'analyse du texte brut HTML sans
modifier la grammaire du script et sans rendre son code sûr. Conserver ou servir
ces ressources relève explicitement de l'application, avec son origine et sa
politique de sécurité du contenu.

Les runtimes de formulaire générés sont aussi externalisés. Ils contiennent le
code de la bibliothèque et aucun corps de script PDF lorsque les scripts PDF
sont désactivés. Ils demandent néanmoins une mise à disposition volontaire
suivant la politique navigateur de l'application.

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

`ParentTreeValidator` possède des modes permissif/strict explicites. Le mode
permissif ne désactive jamais cycles ou budgets cumulatifs. Les frontières inline
suivent des règles exactes de codec ou un fournisseur injecté, sans deviner le
délimiteur binaire. L'export graphique borne ressources/octets, isole les
peintures sélectionnées et supprime les capacités d'exécution du catalogue.
Exécuter le moteur de rendu de l'application reste une décision explicite
et distincte.
L'export contrôle aussi le cumul d'instructions et la profondeur réelle des
ressources, dont les dictionnaires indirects et chaînes d'appels Form. Les
références `/F` externes des flux sont refusées, sans résoudre de fichier local.

Prévisualiser une conversion non fiable dans une origine distincte ou une frame
sandboxée. Cette frontière complète la validation. Ne pas combiner `allow-scripts`
et `allow-same-origin` pour une sortie non fiable.

## Vérifier et signaler

La suite exécutable vérifie sortie sémantique, valeurs hostiles et bornes d'entrée.
Ces contrôles apportent des preuves sur les cas acceptés et ne prouvent pas
l'absence éternelle de vulnérabilité. Signaler les problèmes en privé selon la
[politique de sécurité](../../SECURITY.fr.md).
