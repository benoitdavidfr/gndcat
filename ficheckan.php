<?php
/* Affichage de la fiche CKAN après les transformations suivantes:
 *  - expansion et recompactage pour utiliser le contexte context.yaml
 *  - imbrication des ressources et suppression des noeuds blancs
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/rdfgraph.inc.php';

use Symfony\Component\Yaml\Yaml;
use ML\JsonLD\JsonLD;

switch ($_GET['action'] ?? null) {
  case null: {
    //echo "<a href='?action=Graph'>Utilisation de Graph::frame</a><br>\n";
    echo "<a href='?action=JsonLD'>Utilisation de JsonLD::frame</a><br>\n";
    die();
  }
  /*case 'Graph': {  // utilisation de Graph::frame
    // On commence par une expansion avant de recompacter sur le contexte souhaité
    $expanded = JsonLD::expand('ficheckan.jsonld');

    // On compacte le résultat avec le nouveau contexte
    $compacted = JsonLD::compact(
      $expanded,
      json_encode(Yaml::parseFile(__DIR__.'/context.yaml')));

    // transformation en array
    $compacted = json_decode(json_encode($compacted), true);
    // simplifification du contexte, préférable pour l'affichage
    $compacted['@context'] = 'https://geoapi.fr/gndcat/context.yaml';

    // imbrication du graphe
    $framed = \rdf\Graph::frame($compacted);
    echo '<pre>',Yaml::dump($framed, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    //print_r($compacted);
    die();
  }*/
  case 'JsonLD': { // utilisation de JsonLD::frame(()
    $frame = [
      '@context'=> Yaml::parseFile(__DIR__.'/context.yaml'),
      '@type'=> 'Dataset',
    ];
    $framed = JsonLD::frame('ficheckan.jsonld', json_encode($frame));
    $framed = json_decode(json_encode($framed), true);
    $framed['@context'] = 'https://geoapi.fr/gndcat/context.yaml';
    
    echo '<pre>',Yaml::dump($framed, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    //echo '<pre>'; print_r($framed);
    die();
  }
}
