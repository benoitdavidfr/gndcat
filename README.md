## Test de l'utilisation de DCAT avec Geonetwork (GN)

**Développements en cours**.

L'objectif principal de ce projet est de tester l'utilisation du modèle [DCAT](https://www.w3.org/TR/vocab-dcat-3/)
dans les requêtes [CSW](https://www.ogc.org/standard/cat/) de [Geonetwork](https://geonetwork-opensource.org/).

L'outil de test est disponible sur https://geoapi.fr/gndcat/read.php.

Pour effectuer ces tests un certain nombre de catalogues sont recensés dans l'outil
dans le fichier [servers.yaml](servers.yaml).

Pour afficher une fiche de MD:

  - cliquer sur un catalogue
  - cliquer sur "Liste des dataset (en utilisant MDs)"
  - cliquer sur le titre d'une des fiches ou passer à la page suivante
  
On peut ensuite choisir le format d'affichage en haut avec la possibilité d'en afficher 2 côte-à-côte.

L'affichage ISO des MD est effectué soit en XML, soit après une conversion en JSON définie
dans [mdvars2.inc.php](mdvars2.inc.php) et [isomd.inc.php](isomd.inc.php).
Cette conversion en JSON est partielle par rapport à l'ensemble des éléments de MD définis dans ISO 19115/19139
mais contient au moins tous les éléments définis dans
le [règlement Inspire](https://eur-lex.europa.eu/legal-content/FR/TXT/ELI/?eliuri=eli:reg:2008:1205:oj)
et utilise les XPath définis dans
le [Guide de saisie des éléments de métadonnées INSPIRE, v 2.0, décembre 2019](https://cnig.gouv.fr/IMG/pdf/guide-de-saisie-des-elements-de-metadonnees-inspire-v2.0-1.pdf).

L'affichage DCAT des MD est effectué soit en XML, soit en [Turtle](https://www.w3.org/TR/turtle/),
soit en [YAML-LD](https://json-ld.github.io/yaml-ld/spec/)
(YAML-LD ressemble à du JSON-LD en étant plus lisible) compacté.
Le mécanisme de compactage en JSON-LD/YAML-LD permet de faciliter la lecture d'un document en appliquant un contexte ;
le contexte utilisé ici est défini dans [context.yaml](context.yaml).  

Des tests particuliers ont été effectués sur l'interface Géonetwork de Géo-IDE,
notamment la possibilité d'interroger les métadonnées d'une organisation particulière, par exemple une DDT.
Le résultat de ces derniers tests est plutôt **négatif**
car les libellés des organisations responsables sont assez hétérogènes.
A titre d'info la liste des libellés des organisations, avec pour chacune le nombre de jeux de données associés,
est fourni dans [geoide.orga.yaml](geoide.orga.yaml).

Lors des requêtes aux catalogues les résultats sont mis en cache.
Un message est affiché lorsque cette mise en cache est effectuée.
