## Commentaires sur la fiche CKAN

Fiche est exposée [en JSON-LD](ficheckan.jsonld) et [en Yaml-LD compacté et imbriqué](https://geoapi.fr/gndcat/ficheckan.php).
La [même fiche issue du GeoNetwork de Géo-IDE est dispo ici](http://geoapi.fr/gndcat/read.php?server=gide/gn&action=viewRecord&id=fr-120066022-ldd-74c656c0-3868-4bfa-89d6-5985ce1e8d59&fmt=iso-yaml).

Commentaires:

- globalement le résultat est bien, j'ai cependant les remarques ci-dessous.

- URI de la ressource dcat:Dataset  
  l'URI reprend l'URL de la fiche dans Géo-IDE.  
  Je pense que c'est une mauvaise pratique car je considère que cet URI identifie la fiche de MD DCAT
  et cet identifiant devrait être différent de celui de la fiche de MD ISO.  
  On a déjà eu cette diiscussion avec Leslie et on ne s'était pas mis d'accord.
  
- identifier  
  La fiche de MD ne comporte pas ce champ qui est important et obligatoire dans Inspire.
  On avait eu aussi une discussion avec Leslie sans arriver à un accord.
  L'identifiant du JdD doit être le même dans les différentes fiches de MD
  
  Inspire a été conçu avant la diffusion du RDF et des URI et le réglement prévoit un identifiant constitué de 2 valeurs,
  respectivement un code et un espace de noms définissant le contexte du code.
  Géo-IDE applique cette prescription qui est assez peu respectée.
  J'avais suggéré à Leslie de concaténer l'espace et le code pour créer l'URI mais elle y était opposée.
  
  Sur ces 2 derniers points, il ne me semble pas utile de modifier CKAN, par contre la question va se reposer dans data.gouv.  
  Par ailleurs, je ne pense pas que ca ait beaucoup d'impact sur la presta qualité.

- description/abstract  
  La question peut se poser du choix entre les propriétés abstract et description. Les définitions DCMI sont:
    - abstract:
      definition: A summary of the resource.
    - description:
      definition: An account of the resource. (an français un compte-rendu de la ressource)
      comment: Description may include but is not limited to: an abstract, a table of contents, a graphical representation,
      or a free-text account of the resource.
      
  Le terme abstract correspond donc mieux à la définition Inspire qui est "Bref résumé narratif du contenu de la ressource.".
  Cependant dans la spec DCAT, le champ description est cité alors qu'abstract ne l'est pas.
  
- seriesMember  
  Dans la fiche ISO ce lot contient 3 jeux de données. Dans la fiche CKAN DCAT le lot ne contient qu'un seul jeu.
  Je suppose que c'est un petit bug.
  
- distribution  
  Dans les distributions, il y a une propriété accessService. Je pense qu'elle est inutile et génante.
  En effet cette propriété est normalement utilisée pour décrire un service au travers duquel le JdD est exposé.
  Or, les URL fournis sont ceux du téléchargement des JdD et le service n'est pas utilisé.  
  Par ailleurs, je ne comprends pas très bien la logique de ces différentes distributions mais là on est hors sujet.
