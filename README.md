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
le [règlement Inspire Métadonnées](https://eur-lex.europa.eu/legal-content/FR/TXT/ELI/?eliuri=eli:reg:2008:1205:oj)
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

Le code du proto est principalement dans le fichier [read.php](read.php) qui est décomposé en plusieurs classes:

  - la classe OrgRef gère un référentiel des organisations stocké dans le fichier [orgref.yaml](orgref.yaml) ;
    il est partiel et a été utilisé pour effectuer des tests sur Géo-IDE.
    
  - la classe Cache gère le cache des requêtes Http de manière sommaire.
  
  - la classe CswServer facilite l'utilisation d'un serveur CSW en construisant les URL des requêtes CSW
    et en effectuant les requêtes au travers du cache.
    
  - la classe Turtle facilite l'affichage en Turtle/Html, cad un texte Turtle dans lequel les URL sont transformés
    en lien HTML.
    
  - la classe YamlLD gère des graphes RDF, les transforme en JSON-LD/YAML-LD et effectue l'opération de compactage.
  
  - la classe MDs implémente un itérateur sur les réponses aux GetRecords retournés par un serveur CSW
    pour itérer plus facilement dans les métadonnées retournées.
    
  - la classe RdfServer est utilisée pour tester les points DCAT sans CSW de certains serveurs.

  - enfin le reste du code enchaine les actions demandées soit en CLI soit en web.

De plus:

  - le fichier [http.inc.php](http.inc.php) définit la classe Http qui simplifie l'utilisation de requêtes Http.
  - le fichier [mdvars2.inc.php](mdvars2.inc.php) a été repris de projets précédents,
    il implémente la classe Mdvars qui contient les différents éléments de MD ISO/Inspire et effectue la conversion d'une fice de MD en JSON.
  - la classe IsoMd définie dans le fichier [isomd.inc.php](isomd.inc.php) complète Mdvars
    et simplifie la structure JSON retournée.