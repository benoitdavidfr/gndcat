## Test de l'utilisation de DCAT avec Geonetwork (GN)

**Développements en cours**.

L'objectif principal de ce projet est de tester l'utilisation du modèle [DCAT](https://www.w3.org/TR/vocab-dcat-3/)
dans les requêtes [CSW](https://www.ogc.org/standard/cat/) de [Geonetwork](https://geonetwork-opensource.org/).

L'outil de test est disponible sur https://geoapi.fr/gndcat/read.php.

Pour effectuer ces tests un certain nombre de catalogues sont recensés dans l'outil
dans le fichier [servers.yaml](servers.yaml).

L'affichage des MD ISO est effectué après une conversion en JSON définie dans [mdvars2.inc.php](mdvars2.inc.php)
et [isomd.inc.php](isomd.inc.php). Cette conversion est **partielle**.

L'affichage des MD DCAT est effectué d'une part en [Turtle](https://www.w3.org/TR/turtle/)
et, d'autre part, en [YAML-LD](https://json-ld.github.io/yaml-ld/spec/)
(YAML-LD ressemble à du JSON-LD en étant plus lisible) compacté.
Le mécanisme de compactage en JSON-LD/YAML-LD permet de faciliter la lecture d'un document en appliquant un contexte ;
le contexte utilisé ici est défini dans [context.yaml](context.yaml).  

Des tests particuliers ont été effectués sur l'interface Géonetwork de Géo-IDE,
notamment la possibilité d'interroger les métadonnées d'une organisation particulière, par exemple une DDT.
Le résultat de ces derniers tests est plutôt négatif car les libellés des organisations responsables sont assez hétérogènes.
A titre d'info la liste des libellés des organisations avec pour chacune le nombre de jeux de données associés est fourni
dans [geoide.org.yaml].

Lors des requêtes aux catalogues les résultats sont mis en cache.
Un message est affiché lorsque cette mise en cache est effectuée.
