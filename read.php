<?php
/** Lecture de MD dans différents serveurs et affichage dans différents formats.
 *
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';
require_once __DIR__.'/inspiremd.inc.php';
require_once __DIR__.'/mdserver.inc.php';
require_once __DIR__.'/simpld.inc.php';
require_once __DIR__.'/geodcatap.inc.php';

use Symfony\Component\Yaml\Yaml;
use simpLD\SimpLD;
use simpLD\PropOrder;
use function simpLD\YamlDump;

const VERSION = "2/11/2023";

/** Standardisation des noms des organismes */
class OrgRef {
  const FILE_PATH = __DIR__.'/orgref.yaml';
  /** @var array<string,string> [(altLabel|prefLabel) -> prefLabel] */
  static array $ref=[];
  
  static function init(): void {
    if (!is_file(self::FILE_PATH))
      throw new \Exception("fichier orgref absent");
    $ref = Yaml::parseFile(self::FILE_PATH)['réf'];
    foreach ($ref as $concept) {
      self::$ref[strtolower($concept['prefLabel'])] = $concept['prefLabel'];
      foreach ($concept['altLabel'] ?? [] as $label)
        self::$ref[strtolower($label)] = $concept['prefLabel'];
    }
  }
  
  static function prefLabel(string $label): string {
    return self::$ref[strtolower($label)] ?? $label;
  }
};
OrgRef::init();

/** Affichage d'un document Turtle en Html */
class Turtle {
  readonly public string $turtle; // le code Turtle
  
  /** construction à partir d'un \EasyRdf\Graph */
  function __construct(\EasyRdf\Graph $rdf) {
    $this->turtle = $rdf->serialise('turtle');
  }
  
  /** Transforme le texte turtle en Html */
  function __toString(): string {
    $src = $this->turtle;
    //return str_replace('<', '&lt;', $src);
    $mPrec = '';
    while (preg_match('!<http([^>]+)>!', $src, $matches)) {
      //echo $matches[1],"<br>\n";
      if ($matches[1] == $mPrec)
        throw new Exception("Ca boucle");
      $mPrec = $matches[1];
      $url = $matches[1];
      $urlR = "&lt;<a href='HTTP$url'>HTTP$url</a>&gt;";
      // Il faut échapper les caractères spécifiques de preg_replace()
      $urlp = str_replace(['?','(',')','+'],['\?','\(','\)','\+'], $matches[1]);
      $src = preg_replace("!<http$urlp>!", $urlR, $src);
    }
    return str_replace(["<a href='HTTP",'>HTTP'], ["<a href='http",'>http'], $src);
  }
};

/** Utilisation du point Rdf des GN 3 */
class RdfServer {
  readonly public string $serverId;
  readonly public string $title;
  readonly public Cache $cache;
  
  static function exists(string $serverId): bool { return isset(Server::exists($serverId)['rdfSearchUrl']); }
    
  function __construct(string $serverId, string $cachedir) {
    if (!self::exists($serverId))
      throw new Exception("Erreur, serveur $serverId inexistant");
    $this->serverId = $serverId;
    $this->cache = new Cache("rdf-$cachedir");
    $this->title = Server::exists($serverId)['title'];
  }
  
  function rdfSearchUrl(): ?string { return Server::exists($this->serverId)['rdfSearchUrl'] ?? null; }

  function rdfSearch(): \EasyRdf\Graph {
    $url = $this->rdfSearchUrl();
    $xml = $this->cache->get($url);
    $rdf = new \EasyRdf\Graph($url);
    $rdf->parse($xml, 'rdf', $url);
    return $rdf;
  }
};

/** utilisation d'un point API Records */
class ApiRecords {
  readonly public string $serverId;
  readonly public string $baseUrl;
  /** @var array<string,string|int|number> $httpOptions */
  readonly public array $httpOptions;
  readonly public Cache $cache;
  
  function __construct(string $serverId, string $cachedir) {
    if (!($server = Server::exists($serverId)))
      throw new Exception("Erreur, serveur $serverId inexistant");
    if (!($server['apiRecordsUrl'] ?? null))
      throw new Exception("Erreur, pas de point OGC API Records pour $serverId");
    $this->serverId = $serverId;
    $this->baseUrl = $server['apiRecordsUrl'];
    $this->httpOptions = $server['httpOptions'] ?? [];
    $this->cache = new Cache(str_replace('/', '-', $cachedir), '.json');
    //$this->cache = new Cache('');
  }
  
  function getHome(): string {
    return $this->cache->get($this->baseUrl, $this->httpOptions);
  }
  
  function collections(): string {
    $url = $this->baseUrl.'collections/?f=json';
    echo "<a href='$url'>$url</a><br>\n";
    $result = $this->cache->get($url, $this->httpOptions);
    if ($result === false) {
      var_dump(Http::$lastHeaders);
      var_dump(Http::$lastErrorMessage);
      throw new Exception("Résultat en erreur");
    }
    return $result;
  }
  
  /** @return array<mixed> */
  function items(string $collId): array {
    $url = $this->baseUrl."collections/$collId/items?f=json";
    echo "<a href='$url'>$url</a><br>\n";
    $result = $this->cache->get($url, $this->httpOptions);
    if ($result === false) {
      var_dump(Http::$lastHeaders);
      var_dump(Http::$lastErrorMessage);
      throw new Exception("Résultat en erreur");
    }
    return json_decode($result, true);
  }
};

/** Affichage d'un menu à 2 niveaux */
class Menu2Levels {
  readonly public array $def;
  readonly public string $header; // l'en-tête de début d'affichage
  readonly public string $back; // entrée du menu pour remonter sous la forme d'une cellule de table
  
  /** Création du menu.
   * La définition du menu est un dictionnaire contenant des entrées à 1 ou 2 niveaux.
   * Pour les entrées à 1 niveau le dictionnaire contient [{id}=> {title}].
   * Pour les entrées à 2 niveaux le dictionnaire contient [{id1}=> [0=> {title1}, {id2}=> {title2}]]
   * où:
   *  - {id1} et {title1} sont resp. l'id et le titre de l'entrée de 1er niveau
   *  - {id2} et {title2} sont resp. les id et titres des entrées de second niveau
   * @param array<string,string|array<int|string, string> $def */
  function __construct(array $def, string $header, string $back) {
    $this->def = $def;
    $this->header = $header;
    $this->back = $back;
  }
  
  // prefix est le début des URL
  function asHtml(string $prefix, string $cfmt): string {
    $menu = $this->header;
    $menu .= "<table border=1><tr>";
    // 1e ligne
    foreach ($this->def as $k0 => $val0) {
      if (is_string($val0)) {
        if ($k0 == $cfmt)
          $menu .= "<td><b><div title=\"$val0\">$k0</div></b></td>";
        else
          $menu .= "<td><a href='$prefix&fmt=$k0' title=\"$val0\">$k0</a></td>";
      }
      else {
        $cspan = count($val0) - 1;
        $title = $val0[0];
        $menu .= "<td colspan=$cspan><center><div title=\"$title\">$k0</div></center></td>";
      }
    }
    if ($this->back)
      $menu .= $this->back;
    $menu .= "</tr><tr>\n";
    // 2e ligne
    foreach ($this->def as $k0 => $val0) {
      if (is_string($val0)) {
        $menu .= "<td></td>";
      }
      else {
        foreach ($val0 as $k1 => $title) {
          if (!$k1) continue;
          $f = "$k0-$k1";
          if ($f == $cfmt)
            $menu .= "<td><b><div title=\"$title\">$k1</div></b></td>";
          else
            $menu .= "<td><a href='$prefix&fmt=$f' title=\"$title\">$k1</a></td>";
        }
      }
    }
    if ($this->back)
      $menu .= '<td></td>';
    $menu .= "</tr></table>\n";
    return $menu;
  }
};

/** Affiche les MDs sauf ceux de $typesToSkip
 * @param list<string> $typesToSkip */
function listRecords(string $action, string $id, string $cacheDir, int $startPosition, array $typesToSkip): void {
  $mds = new MdServer($id, $cacheDir, $startPosition);
  $nbreLignes = 0;
  $no = 0;
  echo "<table border=1>\n";
  foreach ($mds as $no => $record) {
    if (in_array($record->dc_type, $typesToSkip)) continue;
    if (++$nbreLignes > NBRE_MAX_LIGNES) break;
    $url = "?server=$id&action=viewRecord&id=".$record->dc_identifier."&startPosition=$startPosition";
    if (!($title = (string)$record->dc_title))
      $title = "(SANS TITRE)";
    echo "<tr><td><a href='$url'>$title</a> ($record->dc_type)</td></tr>\n";
  }
  echo "</table>\n";
  //echo "numberOfRecordsMatched=",$mds->numberOfRecordsMatched(),"<br>\n";
  //echo "no=$no<br>\n";
  //echo "nbre=$nbre<br>\n";
  if ($nbreLignes > NBRE_MAX_LIGNES)
    echo "<a href='?server=$id&action=$action&startPosition=$no'>",
          "suivant ($no / ",$mds->numberOfRecordsMatched(),")</a> / \n";
  else
    echo "$startPosition -> ",$mds->numberOfRecordsMatched()," / \n";
  echo "<a href='?server=$id&action=$action'>Retour au 1er</a> / \n";
  echo "<a href='?server=$id'>Retour à l'accueil</a></p>";
  die();
}

/** Balaie le catalogue indiqué et retourne un array [responsibleParty.name][dataset.id] => 1
 * @return array<string,array<string,1>>
 */
function responsibleParties(string $id, string $cacheDir, callable $stdOrgName): array {
  $mds = new MdServer($id, $cacheDir, 1);
  $rpNames = [];
  foreach ($mds as $no => $record) {
    if (in_array($record->dc_type, ['FeatureCatalogue','service'])) continue;
    //echo " - $record->dc_title ($record->dc_type)\n";
    //echo "$no / ",$mds->numberOfRecordsMatched(),"\n";
    $xml = $mds->getFullGmd();
    $data = InspireMd::convert($xml);
    //echo YamlDump([$data], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    if (!isset($data['responsibleParty'])) {}
    elseif (array_is_list($data['responsibleParty'])) {
      foreach ($data['responsibleParty'] as $responsibleParty) {
        //echo YamlDump([$responsibleParty], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (isset($responsibleParty['name']))
          $rpNames[$stdOrgName($responsibleParty['name'])][(string)$record->dc_identifier] = 1;
      }
    }
    else {
      //echo YamlDump([$data['responsibleParty']], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      if (isset($data['responsibleParty']['name']))
        $rpNames[$stdOrgName($data['responsibleParty']['name'])][(string)$record->dc_identifier] = 1;
    }
  }
  ksort($rpNames);
  return $rpNames;
}

const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>gndcat</title></head><body>\n";
const NBRE_MAX_LIGNES = 35; // nbre d'enregistrements affichchés par page pour GetRecords

if (php_sapi_name() == 'cli') { // utilisation en CLI
  //echo "argc=$argc\n";
  switch ($argc) {
    case 1: {
      echo "usage: php $argv[0] {catalog} {action}\n";
      echo "Liste des serveurs:\n";
      foreach (Server::servers() as $id => $server)
        echo " - $id : $server[title]\n";
      die();
    }
    case 2: {
      $id = $argv[1];
      if (!Server::exists($id))
        die("Erreur le serveur $id n'est pas défini\n");
      echo "Liste des actions:\n";
      echo " - getRecords - lit les enregistrements en brief DC\n";
      echo " - listMdServer - affiche titre et type des métadonnées en utilisant MdServer\n";
      echo " - responsibleParty - affiche les responsibleParty\n";
      echo " - responsiblePartyStd - affiche les responsibleParty standardisés avec le référentiel orgref.yaml\n";
      echo " - idxRespParty - construit un index responsibleParty -> daraset.Id\n";
      echo " - idxRespPartyStd - construit un index responsibleParty -> daraset.Id  std avec le réf. orgref.yaml\n";
      echo " - clearCache - efface le cache\n";
      die();
    }
    case 3: {
      $id = $argv[1];
      $action = $argv[2];
      $server = new CswServer($id, $id);
      switch ($action) {
        case 'clearCache': {
          $server->clearCache();
          die();
        }
        case 'getRecords': {
          $startPosition = 1;
          while ($startPosition) {
            $records = $server->getRecords('dc', 'brief', $startPosition);
            $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
            $records = new SimpleXMLElement($records);
            $numberOfRecordsMatched = $records->csw_SearchResults['numberOfRecordsMatched'];
            $nextRecord = (int)$records->csw_SearchResults['nextRecord'];
            echo "$startPosition/$numberOfRecordsMatched, nextRecord=$nextRecord\n";
            $startPosition = $nextRecord;
            $server->sleep();
          }
          echo 'lastCachepathReturned=',$server->cache->lastCachepathReturned(),"\n";
          die();
        }
        case 'listMdServer': {
          foreach (new MdServer($id, $id, 1) as $no => $md) {
            echo " - $md->dc_title ($md->dc_type)\n";
            //print_r([$no => $md]);
          }
          die();
        }
        case 'responsibleParty': // affiche les responsibleParty non standardisés
        case 'responsiblePartyStd': { // affiche les responsibleParty standardisés
          if ($action == 'responsibleParty')
            $stdOrgNameFun = function(string $orgName): string { return $orgName; };
          else
            $stdOrgNameFun = function(string $orgName): string { return OrgRef::prefLabel($orgName); };
          $rpNames = responsibleParties($id, $id, $stdOrgNameFun);
          foreach ($rpNames as $rpName => $dsids)
            $rpNames[$rpName] = count($dsids);
          echo YamlDump(['names'=> $rpNames]);
          die("FIN\n");
        }
        case 'idxRespParty': { // construit un index responsibleParty -> daraset.Id
          $stdOrgNameFun = function(string $orgName): string { return $orgName; };
          $rpNames = responsibleParties($id, $id, $stdOrgNameFun);
          $idxname = str_replace('/','-',$id).'.resparty.idx.pser';
          file_put_contents(__DIR__."/idx/$idxname", serialize($rpNames));
          die("FIN\n");
        }
        case 'idxRespPartyStd': { // construit un index responsibleParty standardisés -> daraset.Id
          $stdOrgNameFun = function(string $orgName): string { return OrgRef::prefLabel($orgName); };
          $rpNames = responsibleParties($id, $id, $stdOrgNameFun);
          $idxname = str_replace('/','-',$id).'.respartystd.idx.pser';
          file_put_contents(__DIR__."/idx/$idxname", serialize($rpNames));
          die("FIN\n");
        }
        case 'mdDateStat': { // affiche le nbre de mdDate / mois ainsi que la moyenne
          $mds = new MdServer($id, $id, 1);
          $mdDates = [];
          foreach ($mds as $no => $record) {
            if (in_array($record->dc_type, ['FeatureCatalogue','service'])) continue;
            //echo " - $record->dc_title ($record->dc_type)\n";
            //echo "$no / ",$mds->numberOfRecordsMatched(),"\n";
            $data = InspireMd::convert($mds->getFullGmd());
            if (!isset($data['mdDate'])) continue;
            $mdDate = $data['mdDate'];
            //echo "$no -> $mdDate\n";
            $month = substr($mdDate, 0, 7);
            if (!isset($mdDates[$month]))
              $mdDates[$month] = 1;
            else
              $mdDates[$month]++;
          }
          ksort($mdDates);
          echo YamlDump(['$mdDates'=> $mdDates]);
          $sum = 0;
          $nb = 0;
          foreach ($mdDates as $month => $val) {
            if (strcmp($month,'2019') == -1) continue; // je saute avant 2019
            $sum += $val;
            $nb++; 
          }
          echo "moyenne: ",$sum/$nb,"\n";
          die("FIN\n");
        }
        case 'mdQualStat' : { // itère sur les fiches pour afficher celles ayant la meilleure qualité
          $mds = new MdServer($id, $id, 1);
          $quals = []; // [{kqual} => [{id} => {qual}]]
          foreach ($mds as $no => $brief) {
            if (in_array($brief->dc_type, ['FeatureCatalogue','service'])) continue;
            $insmd = InspireMd::convert($mds->getFullGmd());
            $qual = InspireMd::quality($insmd);
            $key = (int)round($qual * 1_000);
            $value = [(string)$brief->dc_identifier => $qual];
            if (!isset($quals[$key]))
              $quals[$key] = [$value];
            else
              $quals[$key][] = $value;
            //echo "$no/",$mds->numberOfRecordsMatched(),"\n";
          }
          ksort($quals);
          echo Yaml::dump($quals);
          die("FIN\n");
        }
        case 'inspireYaml': {
          $mdServer = new MdServer($id, $id, 1);
          foreach ($mdServer as $no => $md) {
            echo " - $md->dc_title ($md->dc_type)\n";
            $xml = $mdServer->getFullGmd();
            $record = InspireMd::convert($xml);
            echo YamlDump([(string)$md->dc_identifier => $record], 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
          }
          die();
        }
        case 'gnturtle': {
          $mdServer = new MdServer($id, $id, 1);
          foreach ($mdServer as $no => $md) {
            echo " - $md->dc_title ($md->dc_type)\n";
            $xml = $mdServer->getFullDcat();
            //echo 'lastCachepathReturned=',$mdServer->server->cache->lastCachepathReturned(),"\n";
            if (!$xml) continue;
            $turtle = $xml->serialise('turtle');
            echo YamlDump([(string)$md->dc_identifier => $turtle]);
          }
          die();
        }
        case 'dap-turtle': {
          $mdServer = new MdServer($id, $id, 1);
          foreach ($mdServer as $no => $md) {
            echo " - $md->dc_title ($md->dc_type)\n";
            $urlGmdFull = $mdServer->server->getRecordByIdCachePath('gmd', 'full', (string)$md->dc_identifier);
            $geoDcatAp = new GeoDcatApUsingXslt($urlGmdFull);
            $rdf = $geoDcatAp->asEasyRdf('core');
            $turtle = $rdf->serialise('turtle');
            echo YamlDump([(string)$md->dc_identifier => $turtle]);
          }
          die();
        }
      }
    }
  }
}
else { // utilisation en mode web
  if (!isset($_GET['server'])) { // choix d'un des catalogues
    echo HTML_HEADER,"<h2>Choix d'un des catalogues connus</h2><ul>\n";
    foreach (Server::servers() as $id => $server) {
      echo "<li><a href='?server=$id'>$server[title]</a></li>\n";
    }
    echo "</ul><a href='?server=error&action=doc'>doc</a><br>\n";
    echo "<a href='?server=error&action=misc'>divers</a></p>\n";
    echo "--<br>version: ",VERSION,"</p>\n";
    die();
  }

  $id = $_GET['server'];
  $server = Server::exists($id);
  if ($server && isset($server['servers'])) {
    echo HTML_HEADER,"<h2>Choix d'un des catalogues connus de \"$server[title]\"</h2><ul>\n";
    foreach ($server['servers'] as $id2 => $server) {
      echo "<li><a href='?server=$id/$id2'>$server[title]</a></li>\n";
    }
    echo "</ul>\n";
    die();
  }
  
  $cswServer = isset($server['cswUrl']) ? new CswServer($id, $id) : null;
  $rdfServer = RdfServer::exists($id) ? new RdfServer($id, $id) : null;
  switch ($_GET['action'] ?? null) { // en fonction de l'action
    case null: { // menu
      echo HTML_HEADER,"<h2>Choix d'une action pour \"$server[title]\"</h2><ul>\n";
      if ($cswServer) {
        echo "<li><a href='",$cswServer->getCapabilitiesUrl(),"'>Lien vers les GetCapabilities</a></li>\n";
        echo "<li><a href='?server=$id&action=GetCapabilities'>GetCapabilities@cache</a></li>\n";
        echo "<li><a href='?server=$id&action=listDatasets'>Liste des dataset (en utilisant MdServer)</a></li>\n";
        echo "<li><a href='?server=$id&action=listServices'>Liste des services (en utilisant MdServer)</a></li>\n";
        echo "<li><a href='?server=$id&action=GetRecords'>GetRecords sans utiliser MdServer pour tests</a></li>\n";
      }
      if ($rdfServer) {
        echo "<li><a href='",$rdfServer->rdfSearchUrl(),"'>Lien vers le point rdf.search</a></li>\n";
        echo "<li><a href='?server=$id&action=rdf'>Affichage du RDF en Turtle</a></li>\n";
      }
      if (isset(Server::exists($id)['apiRecordsUrl'])) {
        $url = Server::exists($id)['apiRecordsUrl'];
        echo "<li><a href='$url' target='_blank'>Lien vers la landingPage API Records</a></li>\n";
        echo "<li><a href='?server=$id&action=collections'>Liste des collections API Records</a></li>\n";
      }
      echo "</ul><a href='?'>Retour à la liste des catalogues.</a></p>\n";
      die();
    }
    case 'GetCapabilities': {
      $xml = $cswServer->getCapabilities();
      echo HTML_HEADER,'<pre>',str_replace('<','&lt;', $xml);
      die();
    }
    case 'GetRecords': { // liste les métadonnées n'utilisant pas MdServer
      //$server = new CswServer($id, '');
      $startPosition = $_GET['startPosition'] ?? 1;
      $url = $cswServer->getRecordsUrl('dc', 'brief', $startPosition);
      echo "<a href='$url'>GetRecords@dc</a></p>\n";
      $results = $cswServer->getRecords('dc', 'brief', $startPosition);
      echo '<pre>',str_replace('<','&lt;',$results),"</pre>\n";
      $results = str_replace(['csw:','dc:'],['csw_','dc_'], $results);
      $results = new SimpleXMLElement($results);
      if ($results->Exception) {
        echo "Exception retournée: ",$results->Exception->ExceptionText;
        die();
      }
      if (!$results->csw_SearchResults)
        die("Résultat erroné");
      echo "<table border=1>\n";
      echo "<tr><td>numberOfRecordsMatched</td><td>",$results->csw_SearchResults['numberOfRecordsMatched'],"</td></tr>\n";
      echo "<tr><td>startPosition</td><td>$startPosition</td></tr>\n";
      $nextRecord = $results->csw_SearchResults['nextRecord'];
      echo "<tr><td>nextRecord</td>",
           "<td><a href='?server=$id&action=$_GET[action]&startPosition=$nextRecord'>$nextRecord</a></td></tr>\n";
      echo "</table></p>\n";
    
      echo "<table border=1>\n";
      foreach ($results->csw_SearchResults->csw_BriefRecord as $record) {
        $url = $cswServer->getRecordByIdUrl('dcat', 'full', $record->dc_identifier);
        echo "<tr><td><a href='$url'>$record->dc_title</a> ($record->dc_type)</td><td>$url</td></tr>\n";
      }
      echo "</table>\n";
      die();
    }
    case 'listDatasets': {  // GetRecords des dataset en utilisant MdServer
      listRecords($_GET['action'], $id, $id, $_GET['startPosition'] ?? 1, ['FeatureCatalogue','service']);
      die();
    }
    case 'listServices': {  // GetRecords des service en utilisant MdServer
      listRecords(
        $_GET['action'], $id, $id, $_GET['startPosition'] ?? 1,
        ['FeatureCatalogue','dataset','series','document','nonGeographicDataset',
         'publication','initiative','software','application','map','repository','']);
      die();
    }
    case 'viewRecord': {
      $fmt = $_GET['fmt'] ?? 'InspireIso-yaml'; // modèle et format
      $startPosition = isset($_GET['startPosition']) ? "&startPosition=$_GET[startPosition]" : '';
      $menu = new Menu2Levels(
        [
          'InspireIso'=> [
            0=> "Inspire/ISO 19139",
            'yaml'=> 'Inspire sérialisé en Yaml',
            'xml'=> 'ISO 19139 complet sérialisé en XML',
          ],
          'GN-DCAT'=> [
            0=> "DCAT généré par GéoNetwork",
            'ttl'=> 'sérialisé en Turtle',
            'yLdi-ds'=> "sérialisé en Yaml-LD contextualisé et imbriqué sur Dataset",
            'yLdi-cr'=> "sérialisé en Yaml-LD contextualisé et imbriqué sur CatalogRecord",
            'yLdi-sv'=> "sérialisé en Yaml-LD contextualisé et imbriqué sur DataService",
            //'dcat-yamlLdc'=> "DCAT sérialisé en Yaml-LD compacté avec le contexte",
            //'dcat-yamlLd'=> "DCAT sérialisé en Yaml-LD non imbriqué et non compacté",
            'xml'=> "sérialisé en RDF/XML",
          ],
          'DCAT-AP'=> [
            0=> "DCAT-AP généré par GeoDCAT-AP API",
            'html'=> "renvoi vers l'API GeoDCAT-AP",
            'ttl'=> "sérialisé en Turtle",
            'yLdi-ds'=> "sérialisé en Yaml-LD contextualisé et imbriqué sur Dataset",
            'yLdi-cr'=> "sérialisé en Yaml-LD contextualisé et imbriqué sur CatalogRecord",
            'yLdi-sv'=> "sérialisé en Yaml-LD contextualisé et imbriqué sur DataService",
          ],
          'GeoDCAT-AP'=> [
            0=> "GeoDCAT-AP généré par l'API GeoDCAT-AP API",
            'ttl'=> " sérialisé en Turtle",
            'yLdi-ds'=> "GeoDCAT-AP généré par GeoDCAT-AP API sérialisé en Yaml-LD contextualisé et imbriqué sur Dataset",
            'yLdi-cr'=> "GeoDCAT-AP généré par GeoDCAT-AP API sérialisé en Yaml-LD contextualisé et imbriqué sur CatalogRecord",
            'yLdi-sv'=> "GeoDCAT-AP généré par GeoDCAT-AP API sérialisé en Yaml-LD contextualisé et imbriqué sur DataService",
          ],
          'double'=> "double affichage",
        ],
        HTML_HEADER,
        $startPosition ? "<td><a href='?server=$id&action=listDatasets$startPosition' target='_parent'>^</a></td>" : ''
      );
      
      $menu = $menu->asHtml("?server=$id&action=viewRecord&id=$_GET[id]$startPosition", $fmt);
      switch ($fmt) {
        case 'InspireIso-yaml': { // InspireMd formatté en Yaml
          echo $menu;
          $xml = $cswServer->getRecordById('gmd', 'full', $_GET['id']);
          $record = InspireMd::convert($xml);
          echo '<pre>',YamlDump($record, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
          die();
        }
        case 'InspireIso-xml': {
          $url = $cswServer->getRecordByIdUrl('gmd', 'full', $_GET['id']);
          header('HTTP/1.1 302 Moved Temporarily');
          header("Location: $url");
          die();
        }
        case 'GN-DCAT-ttl': {
          echo $menu;
          $rdf = $cswServer->getFullDcatById($_GET['id']);
          $turtle = new Turtle($rdf);
          echo "<pre>$turtle</pre>\n";
          die();
        }
        case 'GN-DCAT-yamlLd': {
          echo $menu;
          $rdf = $cswServer->getFullDcatById($_GET['id']);
          $yamlld = new SimpLD($rdf);
          //$compacted = $yamlld->compact();
          echo '<pre>',$yamlld->asYaml(),"</pre>\n";
          die();
        }
        /*case 'dcat-yamlLdc': { // Yaml-LD compacté avec le contexte contextnl.yaml
          echo $menu;
          //echo '<pre>'; print_r(\EasyRdf\Format::getFormats());
          $rdf = $cswServer->getFullDcatById($_GET['id']);
          $yamlld = new SimpLD($rdf);
          echo "<pre>",
                $yamlld
                  ->compact(Yaml::parseFile(__DIR__.'/contextnl.yaml'))
                    ->asYaml(
                      contextURI: 'https://geoapi.fr/gndcat/contextnl.yaml',
                      order: new PropOrder(__DIR__.'/proporder.yaml')
                    ),
               "</pre>\n";
          die();
        }*/
        case 'GN-DCAT-yLdi-ds': // Yaml-LD imbriqué avec le contexte contextnl.yaml et le cadre défini
        case 'GN-DCAT-yLdi-cr': // Yaml-LD imbriqué avec le contexte contextnl.yaml et le cadre défini
        case 'GN-DCAT-yLdi-sv': {
          echo $menu;
          //echo '<pre>'; print_r(\EasyRdf\Format::getFormats());
          $rdf = $cswServer->getFullDcatById($_GET['id']);
          $yamlld = new SimpLD($rdf);
          $frame = [
            '@context'=> Yaml::parseFile(__DIR__.'/contextnl.yaml'),
            '@type'=> match(substr($fmt,-2)) {
              'ds'=> 'Dataset',
              'cr'=> 'CatalogRecord',
              'sv'=> 'DataService',
              default=> die("fmt non interprété ligne ".__LINE__),
            },
          ];
          echo "<pre>",
                $yamlld
                  ->frame($frame)
                    ->asYaml(
                      contextURI: 'https://geoapi.fr/gndcat/contextnl.yaml',
                      order: new PropOrder(__DIR__.'/proporder.yaml')
                    ),
               "</pre>\n";
          die();
        }
        case 'GN-DCAT-xml': {
          $url = $cswServer->getRecordByIdUrl('dcat', 'full', $_GET['id']);
          header('HTTP/1.1 302 Moved Temporarily');
          header("Location: $url");
          die();
        }
        case 'DCAT-AP-html': {
          $urlGmdFull = $cswServer->getRecordByIdUrl('gmd', 'full', $_GET['id']);
          $urlGeoDCATAP = 'https://geodcat-ap.semic.eu/api/?outputSchema=extended&src='.urlencode($urlGmdFull);
          header('HTTP/1.1 302 Moved Temporarily');
          header("Location: $urlGeoDCATAP");
          die();
        }
        case 'DCAT-AP-ttl':
        case 'GeoDCAT-AP-ttl': {
          echo $menu;
          $urlGmdFull = $cswServer->getRecordByIdCachePath('gmd', 'full', $_GET['id']);
          $geoDcatAp = new GeoDcatApUsingXslt($urlGmdFull);
          $rdf = $geoDcatAp->asEasyRdf(substr($fmt,0,1)=='G' ? 'extended' : 'core');
          $turtle = new Turtle($rdf);
          echo "<pre>$turtle</pre>\n";
          die();
        }
        case 'DCAT-AP-yLdi-ds':
        case 'DCAT-AP-yLdi-cr':
        case 'DCAT-AP-yLdi-sv':
        case 'GeoDCAT-AP-yLdi-ds':
        case 'GeoDCAT-AP-yLdi-cr':
        case 'GeoDCAT-AP-yLdi-sv': {
          echo $menu;
          $urlGmdFull = $cswServer->getRecordByIdCachePath('gmd', 'full', $_GET['id']);
          $geoDcatAp = new GeoDcatApUsingXslt($urlGmdFull);
          $rdf = $geoDcatAp->asEasyRdf(substr($fmt,0,1)=='G' ? 'extended' : 'core');
          $yamlld = new SimpLD($rdf);
          $frame = [
            '@context'=> Yaml::parseFile(__DIR__.'/contextdcatap.yaml'),
            '@type'=> match(substr($fmt,-2)) {
              'ds'=> 'Dataset',
              'cr'=> 'CatalogRecord',
              'sv'=> 'DataService',
              default=> die("fmt non interprété ligne ".__LINE__),
            },
          ];
          echo "<pre>",
                $yamlld
                  ->frame($frame)
                    ->asYaml(
                      contextURI: 'https://geoapi.fr/gndcat/contextdcatap.yaml',
                      order: new PropOrder(__DIR__.'/proporder.yaml')
                    ),
               "</pre>\n";
          die();
          
        }
        case 'double': {
          $startPosition = isset($_GET['startPosition']) ? "&startPosition=$_GET[startPosition]" : '';
          echo "<!DOCTYPE HTML>\n<html><head><title>gndcat double</title></head>
    <frameset cols='50%,50%' >
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]$startPosition' name='left'>
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]&fmt=GN-DCAT-yLdi-ds$startPosition' name='right'>
      <noframes>
      	<body>
      		<p><a href='index2.php'>Accès sans frame</p>
      	</body>
      </noframes>
    </frameset>
    ";
          die();
        }
        default: die("Format $fmt inconnu");
      }
    }
    case 'rdf': {
      $fmt = $_GET['fmt'] ?? 'dcat-ttl';
      $menu = HTML_HEADER;
      $url = "?server=$id&action=rdf";
      $menu .= "<table border=1><tr>";
      foreach (['dcat-ttl','dcat-xml'] as $f) {
        if ($f == $fmt)
          $menu .= "<td>$f</td>";
        else
          $menu .= "<td><a href='$url&fmt=$f'>$f</a></td>";
      }
      $menu .= "</table>\n";
      
      switch ($fmt) {
        case 'dcat-ttl': {
          echo $menu;
          $rdf = $rdfServer->rdfSearch();
          $turtle = new Turtle($rdf);
          echo "<pre>$turtle</pre>\n";
          die();
        }
        case 'dcat-xml': {
          $url = $rdfServer->rdfSearchUrl();
          header('HTTP/1.1 302 Moved Temporarily');
          header("Location: $url");
          die();
        }
      }
      die();
    }
    case 'idxRespParty': { // utilise l'index pour lister les jeux de données correspondant à un respParty
      // utilise l'index qui doit être créé en CLI pour lister les jeux de données correspondant à un respParty
      // Ce resParty doit être passé en paramètre GET
      // Des liens permettent d'afficher les MD du JD
      $idxname = str_replace('/','-',$id).'.resparty.idx.pser';
      $rpNames = unserialize(file_get_contents(__DIR__."/idx/$idxname"));
      foreach (array_keys($rpNames[$_GET['respParty']]) as $dsid) {
        echo "<a href='?server=$id&action=viewRecord&id=$dsid'>$dsid</a><br>\n";
      }
      die();
    }
    case 'collections': { // liste des collections du serveur OGC API Records
      echo HTML_HEADER,'<pre>';
      $server = new ApiRecords($id, $id);
      /*
      $home = $server->getHome();
      var_dump($home);
      */
      $colls = json_decode($server->collections(), true);
      echo Yaml::dump($colls, 9);
      foreach ($colls['collections'] as $coll) {
        echo "<a href='?server=$id&action=items&coll=$coll[id]'>$coll[title]</a><br>\n";
      }
      die();
    }
    case 'items': { // Liste des enregistrements de la collection coll du serveur OGC API Records
      echo HTML_HEADER,'<pre>';
      $server = new ApiRecords($id, $id);
      $items = $server->items($_GET['coll']);
      echo Yaml::dump($items, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      die();
    }
    case 'doc': { // doc
      echo HTML_HEADER,"<h2>Docs</h2><ul>";
      echo "<li><b>Docs du projet</b></li><ul>\n";
      echo "<li>Contextes Yaml-LD utilisés pour l'affichage<ul>\n";
      echo "<li><a href='context.yaml'>Contexte en français</a></li>\n";
      echo "<li><a href='contextnl.yaml'>Contexte sans langue</a></li>\n";
      echo "<li><a href='contextdcatap.yaml'>Contexte pour DCAT-AP et GeoDCAT-AP</a></li>\n";
      echo "</ul>\n";
      echo "<li><a href='mdvars2.inc.php'>Noms des éléments de MD utilisés dans le format",
        " \"ISO 19139 Inspire formatté en Yaml\" (iso-yaml)</a></li>\n";
      echo "<li><a href='https://github.com/benoitdavidfr/gndcat' target='_blank'>Source du projet sur Github</a></li>\n";
      // Docs de réf.
      echo "</ul><li><b>Spécifications de référence</b></li><ul>\n";
      echo "<li><a href='https://eur-lex.europa.eu/legal-content/FR/TXT/ELI/?eliuri=eli:reg:2008:1205:oj' target='_blank'>",
        "Règlement (CE) no 1205/2008 de la Commission du 3 décembre 2008 portant modalités d'application",
        " de la directive 2007/2/CE en ce qui concerne les métadonnées</a></li>\n";
      echo "<li><a href='https://portal.ogc.org/files/80534' target='_blank''>",
        "OpenGIS® Catalogue Services Specification 2.0.2 - ISO Metadata Application Profile: Corrigendum</a></li>\n";
      echo "<li><a href='https://portal.ogc.org/files/?artifact_id=51130' target='_blank''>",
        "OpenGIS ® Filter Encoding Implementation Specification, version 1.1.0, 3 May 2005</a></li>\n";
      echo "<li><a href='https://semiceu.github.io/GeoDCAT-AP/drafts/latest/' target='_blank''>",
        "GeoDCAT-AP - Version 2.0.0, A geospatial extension for the DCAT application profile for data portals in Europe,",
        " SEMIC Editor's Draft 23 December 2020</a></li>\n";
      echo "<ul><li><a href='https://semiceu.github.io/GeoDCAT-AP/drafts/latest/#resource-locator---on-line-resource'",
        " target='_blank''>Point sur la traduction des localisateurs de ressource</a></li></ul>\n";
      echo "<li><a href='https://github.com/SEMICeu/iso-19139-to-dcat-ap' target='_blank''>",
        "iso-19139-to-dcat-ap, feuille XSLT pour transformer des MD Inspire en MD GeoDCAT-AP</a></li>\n";
      // GN
      echo "</ul><li><b>GeoNetwork</b></li><ul>\n";
      echo "<li><a href='https://geonetwork-opensource.org/manuals/4.0.x/en/' target='_blank'>",
        "Manuel GeoNetwork v 4</a></li>\n";
      echo "<li><a href='https://github.com/geonetwork/core-geonetwork/pull/6635' target='_blank'>",
        "Pull Request #6635 : CSW / Improve DCAT support (21/10/2022) (version GN 4.2.2)</a></li>\n";
      echo "<li><a href='https://github.com/geonetwork/core-geonetwork/pull/7212' target='_blank'>",
        "Pull Request #7212 :  CSW / GeoDCAT-AP / Add SEMICeu conversion.</a></li>\n";
      
      echo "</ul></ul>\n";
      die();
    }
    case 'misc': {
      echo HTML_HEADER,"<h2>Divers</h2><ul>";
      echo "<li>Exemples de fiches de métadonnées bien remplies:<ul>\n";
      echo "<li><a href='?server=gide/gn-pds&action=viewRecord",
            "&id=fr-120066022-ldd-4bc9b901-1e48-4afd-a01a-cc80e40c35b8'>",
            "sur Géo-IDE</a></li>\n";
      echo "<li><a href='?server=sextant&action=viewRecord&id=34c8d98c-9aea-4bd6-bdf4-9e1041bda08a'>",
            "sur Sextant (GN 4)</a></li>\n";
      echo "<li><a href='?server=sextant&action=viewRecord&id=575cf2fe-3792-4f95-bf81-7253ea1b6188'>",
            "sur Sextant (GN 4) avec temporalExtent</a></li>\n";
      echo "<li><a href='?server=sextant&action=viewRecord&id=e3b0a42a4a843af7a1d5920641f70db8372918ac'>",
            "sur Sextant (GN 4) le service WFS de la DCSMM</a></li>\n";
      echo "<li><a href='?server=sextant&action=viewRecord&id=SDN_CPRD_1850_DGMW_JADE_v2'>",
            "sur Sextant (GN 4) une fiche DCAT-AP avec distributions</a></li>\n";
      echo "<li><a href='?server=geolittoral&action=viewRecord&id=9be3543d-7123-4a44-a000-daba979a9beb'>",
            "sentier du littoral sur GéoLittoral avec distributions</a></li>\n";
      echo "</ul>\n";
      echo "<li><a href='?server=gide/gn&action=idxRespParty&respParty=DDT%20de%20Charente'>",
            "Liste des JD de Géo-IDE GN ayant comme responsibleParty 'DDT de Charente'</a></li>\n";
      echo "</ul>\n";
      die();
    }
    default: die("Action $_GET[action] inconnue");
  }
}
