<?php
/** Application d'un path à un fichier Yaml.
 * Un path est une suite d'éléments séparés par des / commencant par un /.
 * Chaque élément est soit:
 *  - le nom d'une propriété Yaml dans le fichier
 *  - une alternative entre plusieurs noms sous la forme de la liste des noms spérarés par le caractère '|'
 *  - le caractère '*' correspondant à toutes les valeurs possibles de la propriété
 *  - comme dernier élément la chaine '$id' signifiant le retour des clés
 */
require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/** Chaque objet contient le fichier d'origine ou une des instances résultatnt de l'application d'un path */
class BaseData {
  /** @var array<mixed>|string $data */
  readonly public array|string $data;
  
  /** @param array<mixed>|string $data */
  function __construct(array|string $data) { $this->data = $data; }
  
  /** Extrait la liste des valeurs correspondant au path
   * @param list<string> $paths
   * @return array<mixed>
   */
  function path(array $paths): array {
    $result = [];
    foreach ($paths as $path) {
      $path = explode('/', $path);
      array_shift($path);
      $list = new BaseDataList([$this]);
      $result = array_merge($result, $list->path($path)->list);
    }
    
    if (count($result) == 1)
      return $result[0]->data;
    else {
      $result2 = [];
      foreach ($result as $bd) {
        if (is_array($bd->data))
          $result2 = array_merge($result2, $bd->data);
        else
          $result2[] = $bd->data;
      }
    }
    return $result2;
  }
};

/** Liste de BaseData permettant d'iétrer sur le path */
class BaseDataList {
  /** @var list<BaseData> $list */
  readonly public array $list;
  
  /** @param list<BaseData> $list */
  function __construct(array $list) { $this->list = $list; }

  /** Extrait la liste des valeurs correspondant au path.
   * Méthode récursive qui
   *  - à chaque élément du path construit la liste des BaseData qui correspond au résultat du début du path
   *  - part de la liste contenant le BaseData de départ
   * @param list<string> $path
   * @return self
   */
  function path(array $path): self {
    //echo "BaseDataList::path(",implode('/',$path),")<br>\n";
    //echo '$path='; var_dump($path);
    if (!$path)
      return $this;
    if ($path == ['$id']) {
      //return new self(array_keys($this->data));
      return new self(array_map(
        function(BaseData $bd): BaseData { return new BaseData(array_keys($bd->data)); },
        $this->list));
    }
    
    $key = array_shift($path);
    $resultList = []; // list<BaseData>
    foreach ($this->list as $bd) {
      if ($key == '*') {
        foreach ($bd->data as $k => $value) {
          //echo "<pre>k=$k, value="; print_r($value);
          if ($value)
            $resultList[] = new BaseData($value);
        }
      }
      elseif (strpos($key, '|') !== false) {
        foreach (explode('|', $key) as $k) {
          if (isset($bd->data[$k]))
            $resultList[] = new BaseData($bd->data[$k]);
        }
      }
      else {
        if (isset($bd->data[$key]))
          $resultList[] = new BaseData($bd->data[$key]);
      }
    }
    $bdl = new self($resultList);
    return $bdl->path($path);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>path</title></head><body>\n";

if (!isset($_GET['file'])) { // Sélection du nom du fichier
  echo HTML_HEADER;
  echo "<a href='?file=registre'>registre</a><br>\n";
  echo "<a href='?file=reg'>reg</a><br>\n";
  echo "<a href='?file=checkintegrity.test'>checkintegrity.test</a><br>\n";
  die();
}

{ // Formulaires de saisie du path 
  echo "<!DOCTYPE HTML>\n<html><head><title>path ",$_GET['path'] ?? '',"</title></head><body>\n";
  echo "<table><tr><td><form>",
    "<input type=hidden name='file' value='$_GET[file]'>",
    "<input type=text size=50 maxlenth=80 name='path' value=\"",$_GET['path'] ?? '',"\">",
    "<input type=submit value='path'>",
    "</form></td>\n";
  echo "<td><form>",
    "<input type=hidden name='file' value='$_GET[file]'>",
    "<input type=submit value='reset'>",
    "</form></td></tr></table>\n";
}

const EXAMPLES = [
  '/$id'=> "liste des propriétés de 1er niveau",
  '/ontologies'=> "liste des ontologies",
  '/ontologies/$id'=> "liste des curi des ontologies",
  '/ontologies/*'=> "liste des contenus des ontologies (qui a peu de sens car les propriétés communes s'écrasent)",
  '/ontologies/*/title'=> "liste des titres des ontologies",
  '/ontologies/*/classes'=> "liste des classes des ontologies",
  '/ontologies/*/classes/$id'=> "liste des ciri des classes des ontologies",
  '/ontologies/*/classes/*'=> "liste des contenus des classes des ontologies (qui a peu de sens car les propriétés communes s'écrasent)",
  '/ontologies/*/classes/*/definition'=> "liste des définitions des classes des ontologies",
  '/ontologies/*/classes/*/instances'=> "liste des instances des classes des ontologies",
]; // exemples de path affichés lorsque le path n'est pas défini
if (!isset($_GET['path'])) {
  echo "<h2>Exemples de path</h2><ul>\n";
  foreach (EXAMPLES as $path => $title)
    echo "<li><a href='?file=$_GET[file]&path=$path'>$title</a></li>\n";
  echo "</ul>\n";
  die();
}

$baseData = new BaseData(Yaml::parseFile("$_GET[file].yaml"));
$result = $baseData->path([$_GET['path']]);
echo '<pre>',Yaml::dump($result, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
