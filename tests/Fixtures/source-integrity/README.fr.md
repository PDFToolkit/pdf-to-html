# Sources balisées authentifiées de test

[English](README.md) · [Français](README.fr.md)

Ces trois PDF synthétiques originaux proviennent de `../tagged.pdf`, sous licence
MIT, via pyHanko 0.37.0 et cryptography 50.0.2, sans writer PDF PHP.
`manifest.json` conserve les empreintes de la source et des fichiers retenus.
Les identifiants publics de test sont `native-user` et `native-owner`.

Les MAC R6 autonome et attaché à une signature, ainsi que R7 AES-GCM autonome,
vérifient les voies de conversion native et fournisseur. La clé éphémère de
signature a été détruite ; son certificat n’établit aucune confiance. Le hasard
du chiffrement et de la signature produit d’autres octets valides à chaque régénération.

`tools/validate_source_integrity.py` régénère des sources indépendantes
équivalentes, vérifie leur MAC avec pyHanko et la sortie HTML5, puis refuse
sources altérées et mots de passe incorrects. qpdf 12.4.2 contrôle la structure
R6 ; il ne prend pas en charge le chiffrement R7, vérifié sur ses octets originaux
par pyHanko. Aucun oracle ne réencode l’entrée pour masquer un problème
d’interopérabilité. Un MAC source n’authentifie pas le HTML produit.
