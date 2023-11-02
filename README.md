## Test de l'utilisation de DCAT avec Geonetwork (GN)

**Attention, développements en cours !**

L'objectif principal de ce projet est de tester l'utilisation du modèle [DCAT](https://www.w3.org/TR/vocab-dcat-3/)
dans les requêtes [CSW](https://www.ogc.org/standard/cat/) de [GeoNetwork](https://geonetwork-opensource.org/).

L'outil de test est disponible sur https://geoapi.fr/gndcat/read.php.

Pour effectuer ces tests un certain nombre de catalogues sont recensés dans l'outil
dans le fichier [servers.yaml](servers.yaml) dont la structure est décrite par un schéma JSON.

Pour afficher une fiche de MD:

  - cliquer sur un catalogue
  - cliquer sur "Liste des dataset (en utilisant MDs)"
  - cliquer sur le titre d'une des fiches ou passer à la page suivante
  
On peut ensuite choisir le format d'affichage en haut avec la possibilité d'en afficher 2 côte-à-côte.

L'affichage ISO des MD est effectué soit en XML, soit en JSON après une conversion définie
dans [mdvars2.inc.php](mdvars2.inc.php) et [isomd.inc.php](isomd.inc.php).
Cette conversion en JSON est partielle par rapport à l'ensemble des éléments de MD définis dans ISO 19115/19139
mais contient au moins tous les éléments définis dans
le [règlement Inspire Métadonnées](https://eur-lex.europa.eu/legal-content/FR/TXT/ELI/?eliuri=eli:reg:2008:1205:oj)
et utilise les XPath définis dans
le [Guide de saisie des éléments de métadonnées INSPIRE, v 2.0, décembre 2019](https://cnig.gouv.fr/IMG/pdf/guide-de-saisie-des-elements-de-metadonnees-inspire-v2.0-1.pdf).

L'affichage DCAT des MD est effectué soit en XML, soit en [Turtle](https://www.w3.org/TR/turtle/),
soit en [YAML-LD](https://json-ld.github.io/yaml-ld/spec/)
(YAML-LD ressemble à du JSON-LD en étant plus lisible) compacté ou imbriqué.
Le compactage (compact) en JSON-LD/YAML-LD facilite à un humain la lecture d'un document en appliquant un contexte ;
le contexte utilisé ici est défini dans [contextnl.yaml](contextnl.yaml).  
L'imbrication (frame) permet de restructurer le graphe en remplacant certaines réf. par les ressources référencées.

Des tests particuliers ont été effectués sur l'interface GéoNetwork de Géo-IDE,
notamment la possibilité d'interroger les métadonnées d'une organisation particulière, par exemple une DDT.
Le résultat de ces derniers tests est plutôt **négatif**
car les libellés des organisations responsables sont assez hétérogènes.
A titre d'info la liste des libellés des organisations, avec pour chacune le nombre de jeux de données associés,
est fourni dans [geoide.orga.yaml](geoide.orga.yaml).

Lors des requêtes sur les catalogues les résultats sont mis en cache.
Un message est affiché lorsque cette mise en cache est effectuée.

### Organisation du code
Le code du proto est réparti dans les fichiers suivants:

- le fichier [mdserver.inc.php](mdserver.inc.php) gère la logique d'utilisation des serveurs CSW :
  - la classe Cache gère le cache des requêtes Http de manière sommaire.
  - la classe CswServer facilite l'utilisation d'un serveur CSW en construisant les URL des requêtes CSW
    et en effectuant les requêtes au travers du cache associé au serveur.
  - la classe MdServer définit une abstraction de serveur de MD au moyen, d'une part, d'un itérateur sur les fiches de MD
    d'un serveur et, d'autre part, de méthodes retournant le contenu de la fiche courante en XML/ISO-19139 ou en RDF/DCAT.

- le fichier [http.inc.php](http.inc.php) simplifie l'envoi de requêtes Http.

- les fichiers [mdvars2.inc.php](mdvars2.inc.php) et [inspiremd.inc.php](inspiremd.inc.php) simplifient l'utilisation
  des MD Inspire codés en ISO 19139 en se fondant sur les XPath des éléments de MD Inspire définis par le CNIG.

- le fichier [simpLD.inc.php](simpLD.inc.php) définit notamment la classe SimpLD simplifiant l'utilisation d'un graphe
  JSON-LD/Yaml-LD en empaquetant les opérations de JsonLD.

- le fichier [read.php](read.php) définit les classes suivantes:

  - la classe OrgRef gère un référentiel des organisations stocké dans le fichier [orgref.yaml](orgref.yaml) ;
    il est partiel et a été utilisé pour effectuer des tests sur Géo-IDE.
  
  - la classe Turtle facilite l'affichage en Turtle/Html, cad un texte Turtle dans lequel les URL sont transformés
    en liens HTML.
  
  - la classe RdfServer est utilisée pour tester les points DCAT sans CSW de certains serveurs.

  - la classse ApiRecords est utilisée pour tester les serveurs OGC API Records.
  
  - enfin le reste du code enchaine les actions demandées soit en CLI, soit en web.


Enfin, le code utilise les bibliothèques suivantes:

  - https://symfony.com/doc/current/components/yaml.html pour lire et écrire les fichiers Yaml,
  - https://www.easyrdf.org/ pour convertir le RDF entre XML, Turtle et JSON-LD,
  - https://github.com/lanthaler/JsonLD notamment pour compacter et imbriquer un graphe JSON-LD,
  
et est régulièrement testé avec l'[outil PhpStan](https://phpstan.org/).
