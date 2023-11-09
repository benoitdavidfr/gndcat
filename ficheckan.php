<?php
/* Affichage de la fiche CKAN après les transformations suivantes:
 *  - expansion et recompactage pour utiliser le contexte context.yaml
 *  - imbrication des ressources et suppression des noeuds blancs
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/simpld.inc.php';

use Symfony\Component\Yaml\Yaml;
use ML\JsonLD\JsonLD;

switch ($_GET['action'] ?? null) {
  case null: {
    echo "<a href='?action=Turtle'>Sérialisation en Turtle</a><br>\n";
    echo "<a href='?action=SimpLD'>Utilisation de SimpLD::frame</a><br>\n";
    echo "<a href='?action=JsonLD'>Utilisation de JsonLD::frame</a><br>\n";
    die();
  }
  case 'Turtle': {
    $rdf = new \EasyRdf\Graph('http://localhost/');
    $rdf->parse(file_get_contents('ficheckan.jsonld'), 'jsonld', 'http://localhost/');
    //print_r($rdf);
    echo '<pre>',str_replace('<','&lt;',$rdf->serialise('turtle'));
    die();
  }
  case 'SimpLD': {
    $rdf = new \EasyRdf\Graph('http://localhost/');
    $rdf->parse(file_get_contents('ficheckan.jsonld'), 'jsonld', 'http://localhost/');
    $simpLD = new \simpLD\SimpLD($rdf);
    $frame = [
      '@context'=> Yaml::parseFile(__DIR__.'/context.yaml'),
      '@type'=> 'Dataset',
    ];
    echo "<pre>",
          $simpLD
            ->frame($frame)
              ->asYaml(
                contextURI: 'https://geoapi.fr/gndcat/context.yaml',
                order: new \simpLD\PropOrder(__DIR__.'/proporder.yaml')
              ),
         "</pre>\n";
    die();
  }
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
