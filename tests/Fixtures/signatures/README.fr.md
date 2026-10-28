# Jeux de signatures indépendants

Ces petits PDF balisés sont assemblés en syntaxe PDF brute puis signés par la
commande OpenSSL, sans writer ni signataire PDFToolkit. La clé RSA fournie est
**publique et exclusivement réservée aux tests**. Les deux certificats
auto-signés ont expiré en 2021 ; une vérification mathématique réussie ne
constitue pas une décision de confiance envers ces certificats.

`tools/validate_signatures.py --generate-fixtures` reconstruit les PDF avec
l’identité de test conservée. La commande par défaut contrôle les SHA-256
committés, puis les objets PDF et le CMS avec pypdf et OpenSSL indépendamment.
Ajouter `--php php` compare ces verdicts avec le fournisseur réel de validation
des signatures du module HTML. Les dépendances figurent dans les fichiers
existants des dépendances de validation.

Le corpus couvre Contents exact ou complété de zéros, les octets signés altérés,
un certificat différent, un trou d’exclusion élargi avec un CMS valide, un
remplissage non nul, un SubFilter non pris en charge, une révision ultérieure
non signée et un CMS valide sur un préfixe qui ne forme pas une révision PDF
complète. Un dictionnaire de signature copié dans une révision ultérieure
contient les mêmes octets CMS, mais teste la liaison au jeton physique exact
plutôt que leur simple égalité. Le trou élargi et le préfixe sont valides au niveau
cryptographique : leur rejet doit provenir des contrôles de liaison au PDF.

Le cas avec révision ultérieure authentifie seulement la révision signée
initiale. Une mise à jour non signée change le texte visible ; le statut
`integrity-verified` ne statue pas sur les permissions ou les modifications.
