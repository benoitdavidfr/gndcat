<?php
/** convert between RDF formats */

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use ML\JsonLD\JsonLD;

const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>gndcat/convert</title></head><body>\n";

const EXTENSIONS = [
  'rdf' => 'rdfxml',
  'xml' => 'rdfxml',
  'ttl' => 'ttl',
  'jsonld' => 'jsonld',
  'yamlld' => 'yamlld',
]; // formats acceptés en entrée: extension -> nom du format pour EasyRdf

/** Extension d'un chemin */
function extension(string $path): string {
  if (!preg_match('!\.([a-z]+)$!', $path, $matches))
    return '';
  return $matches[1];
}

/** Extrait les noms des classes des ressources à partir d'un graphe compacté */
function extractTypes(array $compacted): array {
  //echo '<pre>compacted='; print_r($compacted);
  $idLabel = '@id';
  $typeLabel = '@type';
  foreach ($compacted['@context'] as $key => $value) {
    if ($value == '@id')
      $idLabel = $key;
    elseif ($value == '@type')
      $typeLabel = $key;
  }
  //echo "idLabel=$idLabel, typeLabel=$typeLabel<br>\n";
  $listOfTypes = [];
  foreach ($compacted['@graph'] as $i => $resource) {
    //echo "resources[$i]="; print_r($resource);
    //echo $resource['$id'],"\n";
    if (is_string($resource[$typeLabel]))
      $listOfTypes[$resource[$typeLabel]] = 1;
    else {
      foreach ($resource[$typeLabel] as $type)
        $listOfTypes[$type] = 1;
    }
  }
  return array_keys($listOfTypes);
}

if (($_GET['action'] ?? null) == 'showFormats') { // afficher les formats proposés par EsayRdf 
  foreach (\EasyRdf\Format::getFormats() as $f) {
    if ($f->getSerialiserClass()) {
      print "<li>$f -> ".$f->getLabel()."</a></li>\n";
    }
  }
  die();
}

$filePath = $_GET['file'] ?? '.';
if (!is_file($filePath)) { // Propose de sélectionner un fichier ayant une des extensions acceptées
  if (!is_dir($filePath))
    die(HTML_HEADER."Erreur, le chemin '$filePath' ne correspond ni à un répertoire, ni à un fichier");
  echo HTML_HEADER,"Sélectionner un fichier RDF:<ul>\n";
  $entries = ['..' => 1];
  foreach (new DirectoryIterator($filePath) as $entry) {
    if ($entry->isDot()) continue;
    if (!is_dir("$filePath/$entry") && !isset(EXTENSIONS[$entry->getExtension()])) continue;
    $entries[(string)$entry] = 1;
  }
  ksort($entries);
  foreach (array_keys($entries) as $entry) {
    echo "<li><a href='?file=$filePath/$entry'>$entry</a></li>\n";
  }
  echo "</ul>\n";
  echo "<a href='?action=showFormats'>Afficher les formats proposés dans EasyRdf</a>.\n";
  die();
}

$ext = extension($filePath);
if (!($fmt = EXTENSIONS[$ext] ?? null)) {
  die("Extension '$ext' non reconnue");
}

if ($fmt == 'yamlld') { // si le fichier est du yamlld alors je le transforme en jsonld
  $fmt = 'jsonld';
  $content = json_encode(Yaml::parseFile($filePath));
}
else {
  $content = file_get_contents($filePath);
}
$rdf = new \EasyRdf\Graph('http://localhost/');
try {
  $rdf->parse($content, $fmt, 'http://localhost/');
}
catch (EasyRdf\Parser\Exception $e) {
  die("Erreur sur le parse RDF: ".$e->getMessage());
}

switch ($_GET['fmt'] ?? null) { // traitement en fonction du format de sortie
  case null: { // choix du format de sortie 
    echo HTML_HEADER,"Sélectionner le format de sortie souhaité:<ul>\n";
    echo "<li><a href='?file=$filePath&fmt=rdf'>RDF/XML</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=ttl'>Turtle</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=jsonld'>jsonld</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=yamlld'>Yaml-LD</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=yamlld-c'>Yaml-LD compacté</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=yamlld-f'>Yaml-LD imbriqué</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=php'>Php</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=dot'>source Graphviz</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=svg'>Graphviz en SVG</a></li>\n";
    echo "<li><a href='?file=$filePath&fmt=png'>Graphviz en PNG</a></li>\n";
    echo "</ul>\n";
    die();
  }
  case 'rdf': {
    //echo HTML_HEADER,'<pre>',str_replace('<', '&lt;', $rdf->serialise($_GET['fmt'])); die();
    header('Content-Type: text/xml');
    echo $rdf->serialise($_GET['fmt']);
    die();
  }
  case 'ttl': {
    echo HTML_HEADER,'<pre>',str_replace('<', '&lt;', $rdf->serialise($_GET['fmt']));
    die();
  }
  case 'php': {
   echo HTML_HEADER,'<pre>',Yaml::dump($rdf->serialise($_GET['fmt']), 10, 2);
   die();
  }
  case 'dot': {
    echo HTML_HEADER,'<pre>',$rdf->serialise($_GET['fmt']);
    die();
  }
  case 'png': {
    header('Content-Type: image/png');
    echo $rdf->serialise($_GET['fmt']);
    die();
  }
  case 'svg': {
    echo $rdf->serialise($_GET['fmt']);
    die();
  }
  case 'jsonld': {
    header('Content-Type: application/json');
    echo $rdf->serialise($_GET['fmt']);
    die();
  }
  case 'yamlld': {
    $json = $rdf->serialise('jsonld');
    echo HTML_HEADER,'<pre>',Yaml::dump(json_decode($json, true), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    die();
  }
  case 'yamlld-c':
  case 'yamlld-f': {
    if (!isset($_GET['context']) || !is_file($_GET['context'])) {
      $context = $_GET['context'] ?? '.';
      echo HTML_HEADER,"Sélectionner le contexte souhaité:<ul>\n";
      foreach (new DirectoryIterator($context) as $entry) {
        if (!in_array($entry->getExtension(),['json','yaml'])) continue;
        echo "<li><a href='?file=$_GET[file]&fmt=$_GET[fmt]&context=$entry'>$entry</a></li>\n";
      }
      die();
    }
    $contextInJSON = match(extension($_GET['context'])) {
      'json'=> file_get_contents($_GET['context']),
      'yaml'=> json_encode(Yaml::parseFile($_GET['context'])),
    };
    $compacted = JsonLD::compact($rdf->serialise('jsonld'), $contextInJSON);
    $compacted = json_decode(json_encode($compacted), true);
    if ($_GET['fmt'] == 'jsonld-c') {
      echo HTML_HEADER,'<pre>',Yaml::dump($compacted, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      die();
    }
    if (!isset($_GET['type'])) {
      echo HTML_HEADER,"Choisir un type:<ul>\n";
      foreach (extractTypes($compacted) as $type) {
        echo "<li><a href='?file=$_GET[file]&fmt=$_GET[fmt]&context=$_GET[context]&type=$type'>$type</a></li>\n";
      }
      echo "</ul>\n";
      die();
    }
    $frame = [
      '@context'=> json_decode($contextInJSON, true),
      '@type'=> $_GET['type'],
    ];
    $framed = JsonLD::frame($rdf->serialise('jsonld'), json_encode($frame));
    $framed = json_decode(json_encode($framed), true);
    echo HTML_HEADER,'<pre>',Yaml::dump($framed, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    die();
  }
  default: die("Format de sortie $_GET[fmt] non reconnu");
}
