<?php
/** Lecture de MD dans différents serveurs et affichage dans différents formats.
 *
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';
require_once __DIR__.'/inspiremd.inc.php';
require_once __DIR__.'/mdserver.inc.php';

use Symfony\Component\Yaml\Yaml;
use ML\JsonLD\JsonLD;

const VERSION = "27/10/2023";

/** supprime les - suivis d'un retour à la ligne dans Yaml::dump() et ajoute par défaut l'option DUMP_MULTI_LINE_LITERAL_BLOCK
 * @param mixed $data
 */
function YamlDump(mixed $data, int $level=3, int $indentation=2, int $options=0): string {
  $options |= Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;
  $dump = Yaml::dump($data, $level, $indentation, $options);
  //return $dump;
  return
    preg_replace('!: \|-ZZ\n!', ": |-\n", 
      preg_replace('!-\n *!', '- ', 
        preg_replace('!(: +)\|-\n!', "\$1|-ZZ\n",
          $dump)));
}

/** Traduit un array en XML.
 * @param array<mixed> $array */
function arrayToXml(array $array, string $prefix=''): string {
  $xml = '';
  if (array_is_list($array)) {
    foreach ($array as $value) {
      $xml .= arrayToXml($value, $prefix);
    }
  }
  else {
    foreach ($array as $key => $value) {
      if (is_array($value)) {
        $xml .= "<$prefix$key>".arrayToXml($value, $prefix)."</$prefix$key>";
      }
      else {
        $xml .= "<$prefix$key>$value</$prefix$key>";
      }
    }
  }
  return $xml;
}
if (0) { // @phpstan-ignore-line // Test arrayToXml()
  $filter = [
    'Filter'=> [
      'PropertyIsEqualTo'=> [
        'PropertyName'=> 'dc:type',
        'Literal'=> 'dataset',
      ],
    ],
  ];
  $yaml = <<<EOT
Filter:
  - PropertyIsEqualTo:
      PropertyName: dc:type
      Literal: dataset
  - PropertyIsLike:
      PropertyName: OrganisationName
      Literal: 37
EOT;
  $yaml = <<<EOT
Filter:
  And:
    - PropertyIsEqualTo: { PropertyName: dc:type, Literal: dataset }
    - PropertyIsLike: { PropertyName: OrganisationName, Literal: DDT de Charente }

EOT;
  $filter = Yaml::parse($yaml);
  //echo "<pre>", str_replace('<','&lt;', arrayToXml($filter, 'ogc:')); die();
  $filreXml = "<Constraint version='1.1.0'>".arrayToXml($filter).'</Constraint>';
  
  header('Content-type: application/xml');
  die($filreXml);
}

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
      $urlp = str_replace(['?','(',')'],['\?','\(','\)'], $matches[1]);
      $src = preg_replace("!<http$urlp>!", $urlR, $src);
    }
    return str_replace(["<a href='HTTP",'>HTTP'], ["<a href='http",'>http'], $src);
  }
};

/** Gestion d'un document Yaml-LD contextualisé */
class YamlLD {
  const CONTEXT_PATH = __DIR__.'/contextnl.yaml';
  const CONTEXT_URI = 'https://geoapi.fr/gndcat/contextnl.yaml';
  /** @var array<mixed> $graph  stockage du document comme array */
  readonly public array $graph;
  
  /** construit un objet soit à partir d'une représentation array JSON soit à partir d'un objet \EasyRdf\Graph
   * @param array<mixed>|\EasyRdf\Graph $rdfOrArray
   */
  function __construct(array|\EasyRdf\Graph $rdfOrArray) {
    if (is_array($rdfOrArray))
      $this->graph = $rdfOrArray;
    else
      $this->graph = json_decode($rdfOrArray->serialise('jsonld'), true);
    // die ("<pre>$this</pre>\n"); // affichage du JSON-LD
  }
  
  function __toString(): string {
    if (count($this->graph['@graph'] ?? [])==1) {
      $graph = ['@context' => self::CONTEXT_URI];
      foreach ($this->graph['@graph'][0] as $p => $o)
        $graph[$p] = $o;
    }
    else {
      $graph = $this->graph;
      if (isset($graph['@context']))
        $graph['@context'] = self::CONTEXT_URI;
    }
    return YamlDump($graph, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }
  
  function compact(): self {
    $compacted = JsonLD::compact(
      json_encode($this->graph),
      json_encode(Yaml::parseFile(self::CONTEXT_PATH)));
    $compacted = json_decode(json_encode($compacted), true);
    //$compacted = $this->improve()
    return new self($compacted);
  }
  
  function frame(): self {
    $frame = [
      '@context'=> Yaml::parseFile(__DIR__.'/contextnl.yaml'),
      '@type'=> 'Dataset',
    ];
    $framed = JsonLD::frame(
      json_encode($this->graph),
      json_encode($frame));
    $framed = json_decode(json_encode($framed), true);
    //$framed['@context'] = 'https://geoapi.fr/gndcat/context.yaml';
    return new self($framed);
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

// utilisation d'un point API Records */
class ApiRecordsServer {
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
      
      var_dump(Http::$lastErrorBody);
      throw new Exception("Résultat en erreur");
    }
    var_dump($result);
    return $result;
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
    echo "<tr><td><a href='$url'>$record->dc_title</a> ($record->dc_type)</td></tr>\n";
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
      $fmt = $_GET['fmt'] ?? 'ins-yaml'; // modèle et format
      $menu = HTML_HEADER;
      $startPosition = isset($_GET['startPosition']) ? "&startPosition=$_GET[startPosition]" : '';
      $url = "?server=$id&action=viewRecord&id=$_GET[id]$startPosition";
      $menu .= "<table border=1><tr>";
      foreach ([
         'ins-yaml'=> 'Inspire formatté en Yaml',
         'iso-xml'=> 'ISO 19139 complet formatté en XML',
         'dcat-ttl'=> 'DCAT formatté en Turtle',
         'dcat-yamlLdi'=> "DCAT formatté en Yaml-LD imbriqué avec le contexte",
         'dcat-yamlLd'=> "DCAT formatté en Yaml-LD non imbriqué et non compacté",
         'dcat-xml'=> "DCAT formatté en RDF/XML",
         'double'=> "double affichage",
          ] as $f => $title) {
        if ($f == $fmt)
          $menu .= "<td><b><div title='$title'>$f</div></b></td>";
        else
          $menu .= "<td><a href='$url&fmt=$f' title='$title'>$f</a></td>";
      }
      if ($startPosition)
        $menu .= "<td><a href='?server=$id&action=listDatasets$startPosition' target='_parent'>^</a></td>";
      $menu .= "</table>\n";
          
      switch ($fmt) {
        case 'ins-yaml': { // InspireMd formatté en Yaml
          echo $menu;
          $xml = $cswServer->getRecordById('gmd', 'full', $_GET['id']);
          $record = InspireMd::convert($xml);
          echo '<pre>',YamlDump($record, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
          die();
        }
        case 'iso-xml': {
          $url = $cswServer->getRecordByIdUrl('gmd', 'full', $_GET['id']);
          header('HTTP/1.1 302 Moved Temporarily');
          header("Location: $url");
          die();
        }
        case 'dcat-ttl': {
          echo $menu;
          $rdf = $cswServer->getFullDcatById($_GET['id']);
          $turtle = new Turtle($rdf);
          echo "<pre>$turtle</pre>\n";
          die();
        }
        case 'dcat-yamlLd': {
          echo $menu;
          $rdf = $cswServer->getFullDcatById($_GET['id']);
          $yamlld = new YamlLD($rdf);
          //$compacted = $yamlld->compact();
          echo "<pre>$yamlld</pre>\n";
          die();
        }
        case 'dcat-yamlLdi': { // Yaml-LD imbriqué avec le contexte contextnl.yaml
          echo $menu;
          //echo '<pre>'; print_r(\EasyRdf\Format::getFormats());
          $rdf = $cswServer->getFullDcatById($_GET['id']);
          $yamlld = new YamlLD($rdf);
          $compacted = $yamlld->frame();
          echo "<pre>$compacted</pre>\n";
          die();
        }
        case 'dcat-xml': {
          $url = $cswServer->getRecordByIdUrl('dcat', 'full', $_GET['id']);
          header('HTTP/1.1 302 Moved Temporarily');
          header("Location: $url");
          die();
        }
        case 'double': {
          $startPosition = isset($_GET['startPosition']) ? "&startPosition=$_GET[startPosition]" : '';
          echo "
    <frameset cols='50%,50%' >
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]$startPosition' name='left'>
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]&fmt=dcat-yamlLd$startPosition' name='right'>
      <noframes>
      	<body>
      		<p><a href='index2.php'>Accès sans frame</p>
      	</body>
      </noframes>
    </frameset>
    ";
          die();
        }
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
    case 'collections': {
      echo HTML_HEADER,'<pre>';
      $server = new ApiRecordsServer($id, $id);
      /*
      $home = $server->getHome();
      var_dump($home);
      */
      $colls = $server->collections();
      var_dump($colls);
      die();
    }
    case 'doc': { // doc
      echo HTML_HEADER,"<h2>Docs</h2><ul>";
      echo "<li><b>Docs du projet</b></li><ul>\n";
      echo "<li><a href='contextnl.yaml'>Contexte utilisé dans le format DCAT Yaml-LD compacté (dcat-yamlLd-c)</a></li>\n";
      echo "<li><a href='mdvars2.inc.php'>Noms des éléments de MD utilisés dans le format",
        " \"ISO 19139 Inspire formatté en Yaml\" (iso-yaml)</a></li>\n";
      // Docs de réf.
      echo "</ul><li><b>Spécifications de référence</b></li><ul>\n";
      echo "<li><a href='https://github.com/benoitdavidfr/gndcat' target='_blank'>Source du projet sur Github</a></li>\n";
      echo "<li><a href='https://eur-lex.europa.eu/legal-content/FR/TXT/ELI/?eliuri=eli:reg:2008:1205:oj' target='_blank'>",
        "Règlement (CE) no 1205/2008 de la Commission du 3 décembre 2008 portant modalités d'application",
        " de la directive 2007/2/CE en ce qui concerne les métadonnées</a></li>\n";
      echo "<li><a href='https://portal.ogc.org/files/80534 target='_blank''>",
        "OpenGIS® Catalogue Services Specification 2.0.2 - ISO Metadata Application Profile: Corrigendum</a></li>\n";
      echo "<li><a href='https://portal.ogc.org/files/?artifact_id=51130 target='_blank''>",
        "OpenGIS ® Filter Encoding Implementation Specification, version 1.1.0, 3 May 2005</a></li>\n";
      // GN
      echo "</ul><li><b>GeoNetwork</b></li><ul>\n";
      echo "<li><a href='https://geonetwork-opensource.org/manuals/4.0.x/en/' target='_blank'>",
        "Manuel GeoNrtwork v 4</a></li>\n";
      echo "<li><a href='https://github.com/geonetwork/core-geonetwork/pull/6635' target='_blank'>",
        "Pull Request #6635 : CSW / Improve DCAT support (21/10/2022) (version GN 4.2.2)</a></li>\n";
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
      echo "</ul>\n";
      echo "<li><a href='?server=gide/gn&action=idxRespParty&respParty=DDT%20de%20Charente'>",
            "Liste des JD de Géo-IDE GN ayant comme responsibleParty 'DDT de Charente'</a></li>\n";
      echo "</ul>\n";
      die();
    }
    default: die("Action $_GET[action] inconnue");
  }
}
