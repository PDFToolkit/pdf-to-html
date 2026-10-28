# Plans d’image décodés et images portables pour le navigateur

[English](../image-codecs.md) · [Français](image-codecs.md)

Le lecteur source HTML utilise `ReadDocument::imageData()` pour les filtres
terminaux DCT, JPX et JBIG2. L’image immuable de Reader conserve la précision, le
signe, la grille, l’origine, le rôle alpha et les métadonnées de couleur de chaque
composante. `PortableImageReader` et `DecodedImagePng` interprètent le dictionnaire
PDF avant de construire la ressource navigateur. Décodage et rendu des couleurs
restent distincts : une image décodée n’est pas nécessairement représentable en
PNG. L’application peut injecter un `ImageCodecs\Decoder` de Reader dans
`DocumentReader` ; la disponibilité des codecs suit sa politique native/PHP.
La couche HTML n’acquiert aucun fichier, n’effectue aucune requête réseau et ne
lance aucun processus par elle-même.

## Interprétation PDF

ISO 32000-2:2020 §7.4.8, table 13 (page physique 64) définit `ColorTransform`
pour DCT et la priorité APP14. §7.4.9 (pages physiques 65–67), avec la table 87
(pages physiques 273–275), définit couleur, précision et opacité JPX :

- Largeur et hauteur PDF doivent correspondre à l’image décodée.
- `BitsPerComponent` est ignoré pour JPX : la précision vient des plans décodés.
- Un `ColorSpace` PDF explicite prime sur les informations de couleur JPX.
  Sans espace explicite, la couleur JPX est utilisée et `Decode` est ignoré,
  sauf pour les images pochoirs, qui nécessitent le renderer graphique explicite.
- Les profils ICC Gray/RGB sont conservés octet pour octet dans `iCCP`. Le `N`
  du stream de profil, ses plages par défaut, son en-tête et les bornes des tags
  sont validés. Ce contrôle de conteneur et de provenance ne certifie pas le profil.
- `SMaskInData=0` ignore le plan d’opacité JPX. Les valeurs 1 et 2 exigent un seul
  canal d’opacité associé à toutes les composantes, de type direct ou prémultiplié
  correspondant. La transparence JPX par clé de couleur est ignorée conformément au PDF.
- Les paires `Decode` identité et inversées sont prises en charge sur les plans
  de codec avec espace PDF explicite. Les autres mappings conservent le programme
  graphique source et nécessitent un renderer.

Le PNG conserve Gray1/2/4/8/16 et RGB8/16. Des profondeurs légales mélangées et
RGB1/2/4 sont étendues uniquement par normalisation entière exacte vers une
profondeur PNG commune. L’alpha exige PNG8 ou PNG16. Chaque échantillon reste
exactement représentable ; aucun plan n’est tronqué à huit bits. Une origine
commune non nulle est translatée sans déplacer les échantillons. Grilles distinctes,
plans signés, précisions non prises en charge, interprétations Indexed/CMYK/Lab/YCbCr,
énumérations RGB différentes de sRGB, plages ICC non standard et masques externes
nécessitent le renderer explicite. L’application conserve l’accès aux plans Reader.

L’alpha prémultiplié est retiré seulement si la division est exacte à la profondeur
PNG retenue. Les échantillons totalement transparents deviennent noirs transparents ;
leur couleur cachée ne contribue pas à l’affichage. Une division non entière produit
un diagnostic, sans ressource approximative. La normalisation entre profondeurs
prises en charge est également exacte. Travail par composante, buffers d’échantillons,
lignes PNG, compression, profil ICC et ressource finale consomment des budgets
cumulatifs propres au document.

## Conservation directe du JPEG

Le JPEG est conservé seulement si ses dimensions, composantes, précision huit bits
et interprétation navigateur correspondent au PDF. `ColorTransform=0` peut donc
imposer un PNG décodé même pour un JPEG ordinaire à trois composantes. APP14 prime
en PDF, mais la priorité JFIF du navigateur et les identifiants ASCII RGB peuvent
rendre la conservation directe incorrecte. Un ICC embarqué ou des métadonnées EXIF
interdisent aussi cette voie : le navigateur pourrait les appliquer indépendamment
de la couleur ou de la matrice PDF. Les chaînes de filtres et sources externes
passent toujours par Reader. L’encodage original reste dans `GraphicProgram`.

Une représentation non prise en charge fournit un diagnostic source explicite et
un substitut dimensionné, ou passe au [`ContentRenderer`](graphics.md) de
l’application. C’est la disposition de conversion indisponible de Deriving HTML
from PDF 1.2 §4.4.3 (pages physiques 53–54), sans perte des plans ni revendication
d’équivalence complète des couleurs entre navigateurs.

## Validation indépendante

`DecodedImagePngTest` couvre promotion exacte de précision, priorité PDF/JPX,
métadonnées, alpha, bornes et compatibilité JPEG navigateur.
`tools/build_codec_images_fixture.py` assemble 23 vrais cas d’image avec pypdf.
Les sources JP2/J2K et échantillons attendus proviennent d’OpenJPEG 2.5.4 exécuté
indépendamment ; deux cas d’opacité prémultipliée supplémentaires sont produits
par Pillow/OpenJPEG avec `tools/build_codec_image_sources.py`.
`tools/validate_codec_images.py` contrôle qpdf, hashes source pypdf, HTML5,
échantillons PNG exacts, profil ICC complet et décodage Pillow. La comparaison
IDCT JPEG tolère un niveau d’échantillon, séparément des comparaisons exactes
JPX/PNG. Quatre cas exigent explicitement un rendu : division alpha non entière,
échantillons signés, précision 12 bits et grilles distinctes.

```sh
python tools/build_codec_image_sources.py --check
python tools/build_codec_images_fixture.py --check
python tools/validate_codec_images.py --php php --export _build/codec-images --report _build/codec-images-validation.json
node tools/validate_codec_images.cjs _build/codec-images _build/codec-images-browser
```

L’oracle choisit explicitement le décodeur PHP optionnel ; installer le package
publié pour cette validation. `--bootstrap` est réservé au test d’un candidat
local, jamais aux dépendances de production. Le harness Chromium/Firefox bloque
le réseau et compare les pixels sur fond blanc. L’arrondi PNG16 vers canvas8 reste
séparé du contrôle exact des seize bits du fichier. Pour ICC, le PNG HTML doit
correspondre **exactement dans le même navigateur** à un PNG Pillow indépendant
portant les mêmes échantillons et le même profil. LittleCMS est mesuré séparément :
Firefox présente un écart allant jusqu’à 9/255 avec le profil Gray conservé, tandis
que profil et témoin dans le même navigateur sont exacts. L’identité des pixels
entre moteurs CMS n’est pas revendiquée.
