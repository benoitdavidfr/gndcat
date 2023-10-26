<?php
/* Affichage de la fiche CKAN après les transformations suivantes:
 *  - expansion et recompactage pour utiliser le contexte context.yaml
 *  - imbrication des ressources et suppression des noeuds blancs
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/frame.inc.php';

use Symfony\Component\Yaml\Yaml;


// On commence par une expansion avant de recompacter sur le contexte souhaité
$expanded = ML\JsonLD\JsonLD::expand('ficheckan.jsonld');

// On compacte le résultat avec le nouveau contexte
$compacted = ML\JsonLD\JsonLD::compact(
  $expanded,
  json_encode(Yaml::parseFile(__DIR__.'/context.yaml')));

// transformation en array
$compacted = json_decode(json_encode($compacted), true);
// simplifification du contexte, préférable pour l'affichage
$compacted['@context'] = 'https://geoapi.fr/gndcat/context.yaml';

// imbrication du graphe
$compacted = Resource::frameGraph($compacted);
echo '<pre>',Yaml::dump($compacted, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
//print_r($compacted);