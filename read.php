<?php
// Lecture basique des MD en CSW/DCAT
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';
require_once __DIR__.'/isomd.inc.php';

use Symfony\Component\Yaml\Yaml;

/** supprime les - suivis d'un retour à la ligne dans Yaml::dump()
 * @param mixed $data
 */
function YamlDump(mixed $data, int $level=3, int $indentation=2, int $options=0): string {
  $options |= Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK;
  $dump = Yaml::dump($data, $level, $indentation, $options);
  //return $dump;
  //return preg_replace('!-\n *!', '- ', $dump);
  return preg_replace('!: \|-ZZ\n!', ": |-\n", preg_replace('!-\n *!', '- ', preg_replace('!(: +)\|-\n!', "\$1|-ZZ\n", $dump)));
}

/** Traduit un array en XML */
function arrayToXml(array $array, string $prefix=''): string {
  $xml = '';
  foreach ($array as $key => $value) {
    if (is_array($value)) {
      $xml .= "<$prefix$key>".arrayToXml($value, $prefix)."</$prefix$key>";
    }
    else {
      $xml .= "<$prefix$key>$value</$prefix$key>";
    }
  }
  return $xml;
}
if (0) { // Test arrayToXml()
  $filter = [
    'Filter'=> [
      'PropertyIsEqualTo'=> [
        'PropertyName'=> 'dc:type',
        'Literal'=> 'dataset',
      ],
    ],
  ];
  echo "<pre>", str_replace('<','&lt;', arrayToXml($filter, 'ogc:')); die();
}

class Turtle {
  /** Transforme le texte turtle en Html */
  static function html(string $src): string {
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

/** Gestion d'un cache pour des requêtes http */
class Cache {
  readonly public string $cachedir; // '' si pas de cache
  protected bool $lastOperationWasInCache = true;
  protected string $lastCachepathReturned = '';
  
  function __construct(string $cachedir) {
    if (!$cachedir) {
      $this->cachedir = '';
      return;
    }
    
    $cachedir = __DIR__."/cache/$cachedir";
    if (!is_dir(__DIR__."/cache"))
      mkdir(__DIR__."/cache");
    if (!is_dir($cachedir))
      mkdir($cachedir);
    $this->cachedir = $cachedir;
  }
  
  /** Retourne le chemin du fichier de cache pour une URL donnée */
  function id(string $url): string {
    if (!$this->cachedir)
      return '';
    else
      return $this->cachedir.'/'.md5($url).'.xml';
  }
  
  /** Retrouve en GET le doc correspondant à l'URL en utilisant le cache */
  function get(string $url): string {
    if (!$this->cachedir) {
      $this->lastOperationWasInCache = false;
      return file_get_contents($url);
    }
    $cachepath = $this->cachedir.'/'.md5($url).'.xml';
    if (is_file($cachepath)) {
      //echo "$url en cache\n";
      $this->lastOperationWasInCache = true;
      $this->lastCachepathReturned = $cachepath;
      return file_get_contents($cachepath);
    }
    $this->lastOperationWasInCache = false;
    $contents = file_get_contents($url);
    if ($contents === false)
      throw new Exception("Erreur '".($http_response_header[0] ?? 'unknown')."' sur url=$url");
    file_put_contents($cachepath, $contents);
    $this->lastCachepathReturned = $cachepath;
    return $contents;
  }
  
  /** Retrouve le document correspondant à l'URL et aux options en utilisaant le cache */
  function request(string $url, array $options): array {
    if (!$this->cachedir) {
      $this->lastOperationWasInCache = false;
      return Http::request($url, $options);
    }
    $doc = array_merge(['cswUrl'=> $url], $options);
    $doc = json_encode($doc);
    //echo $doc; die();
    $cachepath = $this->cachedir.'/'.md5($doc).'.json';
    if (is_file($cachepath)) {
      $this->lastOperationWasInCache = true;
      $this->lastCachepathReturned = $cachepath;
      return json_decode(file_get_contents($cachepath), true);
    }
    $this->lastOperationWasInCache = false;
    $contents = Http::request($url, $options);
    if ($contents === false)
      throw new Exception("Erreur '".($http_response_header[0] ?? 'unknown')."' sur url=$url");
    file_put_contents($cachepath, json_encode($contents));
    $this->lastCachepathReturned = $cachepath;
    return $contents;
  }

  function lastOperationWasInCache(): bool { return $this->lastOperationWasInCache; }
  
  function lastCachepathReturned(): string { return $this->lastCachepathReturned; }

  function clear(): void {
    $output = null;
    $retval = null;
    exec("rm -r ".$this->cachedir, $output, $retval);
    if ($retval)
      echo "Returned with status $retval and output:\n";
    else
      echo "Cache effacé\n";
    if ($output)
      print_r($output);
  }
};

class CswServer {
  /** Paramètres du GetRecords en fonction du type de retour souhaité */
  const GETRECORDS_PARAMS = [
    'dc'=> [
      'OutputSchema' => 'http://www.opengis.net/cat/csw/2.0.2',
      'namespace' => 'xmlns(csw=http://www.opengis.net/cat/csw)',
      'TypeNames' => 'csw:Record',
    ],
    'gmd'=> [
      'OutputSchema' => 'http://www.isotc211.org/2005/gmd',
      'namespace' => 'xmlns(gmd=http://www.isotc211.org/2005/gmd)',
      'TypeNames' => 'gmd:MD_Metadata',
    ],
    'mdb'=> [
      'OutputSchema' => 'http://standards.iso.org/iso/19115/-3/mdb/2.0',
      'namespace' => 'xmlns(mdb=http://standards.iso.org/iso/19115/-3/mdb/2.0)',
      'TypeNames' => 'mdb:MD_Metadata',
    ],
    'dcat'=> [
      'OutputSchema' => 'http://www.w3.org/ns/dcat#',
      'namespace' => 'xmlns(dcat=http://www.w3.org/ns/dcat#)',
      'TypeNames' => 'dcat',
    ],
    'dcat-ap'=> [
      'OutputSchema' => 'http://data.europa.eu/930/',
      'namespace' => 'xmlns(dcat-ap=http://data.europa.eu/930/)',
      'TypeNames' => 'dcat-ap',
    ],
    'gfc'=> [
      'OutputSchema' => 'http://www.isotc211.org/2005/gfc',
      'namespace' => 'xmlns(gfc=http://www.isotc211.org/2005/gfc)',
      'TypeNames' => 'gfc:FC_FeatureCatalogue',
    ],
  ];
  
  static array $servers;
  readonly public string $serverId;
  readonly public string $title;
  readonly public bool $post;
  readonly public array $filter; // filtre des GetRecords en POST
  readonly public Cache $cache;
  
  /** Retourne la liste des serveurs et leurs caractéristiques */
  static function servers(): array {
    if (!isset(self::$servers))
      self::$servers = Yaml::parseFile(__DIR__.'/servers.yaml')['servers'];
    return self::$servers;
  }
  
  /** Retourne les caractéritiques du serveur s'il existe et sinon null */
  static function exists(string $serverId): ?array { return self::servers()[$serverId] ?? null; }
  
  function __construct(string $serverId, string $cachedir) {
    if (!($server = self::exists($serverId)))
      throw new Exception("Erreur, serveur $serverId inxeistant");
    $this->serverId = $serverId;
    $this->cache = new Cache($cachedir);
    $this->title = $server['title'];
    $this->post = isset($server['post']);
    $this->filter = $server['post']['filter'] ?? [];
  }
  
  /** Retourne l'URL du GetCapabilities */
  function getCapabilitiesUrl(): string {
    return self::servers()[$this->serverId]['cswUrl'].'?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetCapabilities';
  }
  
  /** Retourne le document du GetCapabilities en utilisant le cache */
  function getCapabilities(): string { return $this->cache->get($this->getCapabilitiesUrl()); }
  
  /** Retourne l'URL du GetRecords en GET */
  function getRecordsUrl(string $type, string $ElementSetName, int $startPosition, array $filter=[]): string {
    $OutputSchema = urlencode(self::GETRECORDS_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::GETRECORDS_PARAMS[$type]['namespace']);
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];
    if ($filter)
      $filter = urlencode(arrayToXml($filter));

    return self::servers()[$this->serverId]['cswUrl']
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecords&ElementSetName=$ElementSetName"
      .'&ResultType=results&MaxRecords=10&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&startPosition=$startPosition"
      .($filter ? "&CONSTRAINTLANGUAGE=FILTER&CONSTRAINT_LANGUAGE_VERSION=1.1.0&FILTER=$filter" : '');
  }
  
  /** Réalise un GetRecords soit en GET soit en POST en fonction du paramétrage du serveur */
  function getRecords(string $type, string $ElementSetName, int $startPosition): string {
    if ($this->post) {
      return $this->getRecordsInPost($type, $ElementSetName, $startPosition, $this->filter);
    }
    else {
      return $this->getRecordsInGet($type, $ElementSetName, $startPosition);
    }
  }
  
  /** Réalise un GetRecords en GET */
  private function getRecordsInGet(string $type, string $ElementSetName, int $startPosition, array $filter=[]): string {
    //echo "CswServer::getRecords(startPosition=$startPosition)<br>\n";
    $url = $this->getRecordsUrl($type, $ElementSetName, $startPosition, $filter);
    return $this->cache->get($url);
  }
  
  /** Réalise un GetRecords en POST */
  private function getRecordsInPost(string $type, string $ElementSetName, int $startPosition, array $filter=[]): string {
    $OutputSchema = self::GETRECORDS_PARAMS[$type]['OutputSchema'];
    $namespace = self::GETRECORDS_PARAMS[$type]['namespace'];
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];
    
    $query = '<?xml version="1.0" encoding="utf-8"?>'."\n"
      ."<GetRecords service='CSW' version='2.0.2' maxRecords='10'\n"
      ."  startPosition='$startPosition' resultType='results' outputFormat='application/xml'\n"
      ."  outputSchema='$OutputSchema' xmlns='http://www.opengis.net/cat/csw/2.0.2'\n"
      ."  xmlns:ogc='http://www.opengis.net/ogc' xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance'\n"
      ."  xsi:schemaLocation='http://schemas.opengis.net/csw/2.0.2/CSW-discovery.xsd'>\n"
      ."  <Query typeNames='$TypeNames'>\n"
      ."    <ElementSetName>$ElementSetName</ElementSetName>\n"
      .($filter ? "<Constraint version='1.1.0'>".arrayToXml($filter, 'ogc:').'</Constraint>' : '')
      ."  </Query>\n"
      ."</GetRecords>";
      
      
      {/*<csw:GetRecords xmlns:dc="http://purl.org/dc/elements/1.1/"
        xmlns:dct="http://purl.org/dc/terms/"
        xmlns:csw="http://www.opengis.net/cat/csw/2.0.2"
        service="CSW"
        resultType="results" startPosition="1"
        version="2.0.2"
        outputSchema="http://www.opengis.net/cat/csw/2.0.2">
        <csw:Query typeNames="csw:Record"
          xmlns:ogc="http://www.opengis.net/ogc"
          xmlns:gml="http://www.opengis.net/gml">
          <csw:ElementSetName>brief
          <csw:Constraint version="1.1.0">
            <ogc:Filter xmlns:ogc="http://www.opengis.net/ogc"
              xmlns:gml="http://www.opengis.net/gml">
            <ogc:PropertyIsLike escape="" singleChar="_" wildCard="%">
              <ogc:PropertyName>Title</ogc:PropertyName>
              <ogc:Literal>%risques%</ogc:Literal>
            </ogc:PropertyIsLike>
          </ogc:Filter>
          </csw:Constraint>
        </csw:Query>
      </csw:GetRecords>*/}
        
      {/* Exemple donné dans le standard
        <GetRecords xmlns="http://www.opengis.net/cat/csw/2.0.2"
        xmlns:csw="http://www.opengis.net/cat/csw/2.0.2"
        xmlns:ogc="http://www.opengis.net/ogc" xmlns:ows="http://www.opengis"
        xmlns:xsd="http://www.w3.org/2001/XMLSchema"
        xmlns:dc="http://purl.org/dc/elements/1.1/"
        xmlns:dct="http://purl.org/dc/terms/" xmlns:gml="http://www.opengis.net/gml"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" service="CSW"
        version="2.0.2" resultType="results" outputFormat="application/xml"
        outputSchema="http://www.opengis.net/cat/csw/2.0.2" startPosition="1"
        maxRecords="5">
        <Query typeNames="csw:Record">
          <ElementSetName typeNames="">brief</ElementSetName>
          <Constraint version="1.1.0">
            <ogc:Filter>
              <ogc:And>
                <ogc:PropertyIsLike escapeChar="\" singleChar="?" wildCard="*">
                  <ogc:PropertyName>dc:title</ogc:PropertyName>
                  <ogc:Literal>*spectra*</ogc:Literal>
                </ogc:PropertyIsLike>
                <ogc:PropertyIsEqualTo>
                  <ogc:PropertyName>dc:type</ogc:PropertyName>
                  <ogc:Literal>dataset</ogc:Literal>
                </ogc:PropertyIsEqualTo>
                <ogc:Intersects>
                  <ogc:PropertyName>ows:BoundingBox</ogc:PropertyName>
                  <gml:Envelope>
                    <gml:lowerCorner>14.05 46.46</gml:lowerCorner>
                    <gml:upperCorner>17.24 48.42</gml:upperCorner>
                  </gml:Envelope>
                </ogc:Intersects>
              </ogc:And>
            </ogc:Filter>
          </Constraint>
        </Query>
      </GetRecords> */}
        
    
    echo "getRecordsInPost(query=",str_replace('<','&lt;',$query),")<br>\n";
    $options = [
      'method'=> 'POST',
      'Content-Type'=> 'application/xml',
      'ignore_errors' => true, // pour éviter la génération d'une exception
      'content'=> $query,
    ];
    $result = $this->cache->request(self::servers()[$this->serverId]['cswUrl'], $options);
    $body = $result['body'];
    $result['body'] = str_replace('<','&lt;', $result['body']);
    echo "<pre>"; print_r($result); echo "</pre>\n";
    return $body;
  }
  
  /** Retourne l'URL d'un GetRecordById */
  function getRecordByIdUrl(string $type, string $ElementSetName, string $id): string {
    $OutputSchema = urlencode(self::GETRECORDS_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::GETRECORDS_PARAMS[$type]['namespace']);
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];
    return self::servers()[$this->serverId]['cswUrl']
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById&ElementSetName=$ElementSetName"
      .'&ResultType=results&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&id=".$id;
  }
  
  /** Retourne le GetRecordById */
  function getRecordById(string $type, string $ElementSetName, string $id): string {
    $url = $this->getRecordByIdUrl($type, $ElementSetName, $id);
    return $this->cache->get($url);
  }

  /** suppression du cache */
  function clearCache(): void { $this->cache->clear(); }
  
  /** attente du délai défini s'il est défini et si la dernière opération n'était pas en cache.
   *
   * L'attente est destinée à ne pas stresser le catalogue moissoné.
   */
  function sleep(): void {
    //echo "sleep()\n";
    if ($this->cache->lastOperationWasInCache())
      return;
    $delay = self::servers()[$this->serverId]['delay'] ?? 0;
    if ($delay >= 1) {
      //echo "sleep($delay)\n";
      sleep($delay);
    }
    else {
      $delayMicroSec = (int)round($delay * 1_000_000);
      //echo "usleep($delayMicroSec)\n";
      usleep($delayMicroSec);
    }
  }
};

/** Itérateur sur les métadonnées DC brief */
class MDs implements Iterator {
  readonly public CswServer $server; // Serveur CSW sous-jacent
  readonly public int $startPos; // position de démarrage définie à la création
  //protected string $type;
  protected int $firstPos; // première position du buffer courant
  protected SimpleXMLElement $records; // tableau des enregistrements courants
  protected int $numberOfRecordsMatched;
  protected int $nextRecord; // no d'enregistrement du prochain buffer
  protected int $currentPos; // position courante dans l'itérateur
  
  function __construct(string $serverId, string $cachedir, int $startPosition) {
    $this->server = new CswServer($serverId, $cachedir);
    $this->startPos = $startPosition;
  }
  
  function numberOfRecordsMatched(): int { return $this->numberOfRecordsMatched; }
  
  /** lecture d'un buffer de records à partir de firstPos */
  private function getBuffer() {
    if ($this->server->post)
      $records = $this->server->getRecords('dc', 'brief', $this->firstPos, $this->filter);
    else
      $records = $this->server->getRecords('dc', 'brief', $this->firstPos);
    $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
    $this->records = new SimpleXMLElement($records);
    $this->numberOfRecordsMatched = (int)$this->records->csw_SearchResults['numberOfRecordsMatched'];
    $this->nextRecord = (int)$this->records->csw_SearchResults['nextRecord'];
  }
  
  function rewind(): void {
    //echo "rewind()<br>\n";
    $this->currentPos = $this->startPos;
    $this->firstPos = floor(($this->startPos - 1) / 10) * 10 + 1;
    $this->getBuffer();
  }
  
  function current(): SimpleXMLElement {
    //echo "current()<br>\n";
    //echo "firstPos=$this->firstPos\n";
    //echo "currentPos=$this->currentPos\n";
    return $this->records->csw_SearchResults->csw_BriefRecord[$this->currentPos - $this->firstPos];
  }
  
  function key(): int {
    //echo "key()<br>\n";
    return $this->currentPos;
  }
  
  function next(): void {
    //echo "next()<br>\n";
    $this->currentPos++;
    if (($this->currentPos - $this->firstPos) >= 10) {
      $this->firstPos = $this->nextRecord;
      if (!$this->firstPos)
        throw new Exceprion("Erreur firstPos==0");
      $this->getBuffer();
    }
  }
  
  function valid(): bool {
    //echo "valid()<br>\n";
    return ($this->currentPos <= $this->numberOfRecordsMatched);
  }
};

/** Utilisation du point Rdf des GN 3 */
class RdfServer {
  readonly public string $serverId;
  readonly public string $title;
  readonly public Cache $cache;
  
  static function exists(string $serverId): bool { return isset(cswServer::servers()[$serverId]['rdfSearchUrl']); }
    
  function __construct(string $serverId, string $cachedir) {
    if (!self::exists($serverId))
      throw new Exception("Erreur, serveur $serverId inexistant");
    $this->serverId = $serverId;
    $this->cache = new Cache("rdf-$cachedir");
    $this->title = cswServer::servers()[$serverId]['title'];
  }
  
  function rdfSearchUrl(): ?string { return cswServer::servers()[$this->serverId]['rdfSearchUrl'] ?? null; }

  function rdfSearch(): ?string { return $this->cache->get($this->rdfSearchUrl()); }
};

const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>gndcat</title></head><body>\n";
const NBRE_MAX_LIGNES = 38;

if (php_sapi_name() == 'cli') { // utilisation en CLI
  //echo "argc=$argc\n";
  switch ($argc) {
    case 1: {
      echo "usage: php $argv[0] {catalog} {action}\n";
      echo "Liste des serveurs:\n";
      foreach (CswServer::servers() as $id => $server)
        echo " - $id : $server[title]\n";
      die();
    }
    case 2: {
      $id = $argv[1];
      if (!CswServer::exists($id))
        die("Erreur le serveur $id n'est pas défini\n");
      echo "Liste des actions:\n";
      echo " - getRecords - lit les enregistrements en brief DC\n";
      echo " - listMDs - affiche titre et type des métadonnées en utilisant MDs\n";
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
        /*case 'list': {
          $startPosition = 1;
          while ($startPosition) {
            $records = $server->getRecords('dc', 'brief', $startPosition);
            $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
            $records = new SimpleXMLElement($records);
            $numberOfRecordsMatched = $records->csw_SearchResults['numberOfRecordsMatched'];
            $startPosition = (int)$records->csw_SearchResults['nextRecord'];
            if (!$records->csw_SearchResults)
              die("Résultat erroné");
            foreach ($records->csw_SearchResults->csw_BriefRecord as $record) {
              echo " - $record->dc_title ($record->dc_type)\n";
            }
          }
          die();
        }*/
        case 'listMDs': {
          foreach (new MDs($id, $id, 1) as $no => $md) {
            echo " - $md->dc_title ($md->dc_type)\n";
            //print_r([$no => $md]);
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
    foreach (CswServer::servers() as $id => $server) {
      echo "<li><a href='?server=$id'>$server[title]</a></li>\n";
    }
    die();
  }

  $id = $_GET['server'];
  $server = new CswServer($id, $id);
  $rdfServer = RdfServer::exists($id) ? new RdfServer($id, $id) : null;
  switch ($_GET['action'] ?? null) { // en fonction de l'action
    case null: { // menu
      echo HTML_HEADER,"<h2>Choix d'une action pour \"$server->title\"</h2><ul>\n";
      echo "<li><a href='",$server->getCapabilitiesUrl(),"'>GetCapabilities</a></li>\n";
      echo "<li><a href='?server=$id&action=GetCapabilities'>GetCapabilities@cache</a></li>\n";
      echo "<li><a href='?server=$id&action=GetRecords'>GetRecords sans utiliser MDs</a></li>\n";
      echo "<li><a href='?server=$id&action=listDatasets'>GetRecords des dataset en utilisant MDs</a></li>\n";
      if ($rdfServer) {
        echo "<li><a href='",$rdfServer->rdfSearchUrl(),"'>Affichage du contenu du point rdf.search</a></li>\n";
        echo "<li><a href='?server=$id&action=rdf'>Affichage du RDF en Turtle</a></li>\n";
      }
      if (isset(CswServer::servers()[$id]['ogcApiRecordsUrl'])) {
        $url = CswServer::servers()[$id]['ogcApiRecordsUrl'];
        echo "<li><a href='$url'>ogcApiRecordsUrl</a></li>\n";
      }
      die();
    }
    case 'GetCapabilities': {
      $xml = $server->getCapabilities();
      echo HTML_HEADER,'<pre>',str_replace('<','&lt;', $xml);
      die();
    }
    case 'GetRecords': { // liste les métadonnées n'utilisant pas MDs
      //$server = new CswServer($id, '');
      $startPosition = $_GET['startPosition'] ?? 1;
      $url = $server->getRecordsUrl('dc', 'brief', $startPosition);
      echo "<a href='$url'>GetRecords@dc</a></p>\n";
      $results = $server->getRecords('dc', 'brief', $startPosition);
      echo '<pre>',str_replace('<','&lt;',$results),"</pre>\n";
      $results = str_replace(['csw:','dc:'],['csw_','dc_'], $results);
      $results = new SimpleXMLElement($results);
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
        $url = $server->getRecordByIdUrl('dcat', 'full', $record->dc_identifier);
        echo "<tr><td><a href='$url'>$record->dc_title</a> ($record->dc_type)</td><td>$url</td></tr>\n";
      }
      echo "</table>\n";
      die();
    }
    case 'listDatasets': {  // GetRecords des dataset en utilisant MDs
      $startPosition = $_GET['startPosition'] ?? 1;
      $mds = new MDs($id, $id, $startPosition);
      $nbreLignes = 0;
      echo "<table border=1>\n";
      foreach ($mds as $no => $record) {
        if (in_array($record->dc_type, ['FeatureCatalogue','service'])) continue;
        if (++$nbreLignes > NBRE_MAX_LIGNES) break;
        $url = "?server=$id&action=viewRecord&id=".$record->dc_identifier."&startPosition=$startPosition";
        echo "<tr><td><a href='$url'>$record->dc_title</a> ($record->dc_type)</td></tr>\n";
      }
      echo "</table>\n";
      //echo "numberOfRecordsMatched=",$mds->numberOfRecordsMatched(),"<br>\n";
      //echo "no=$no<br>\n";
      //echo "nbre=$nbre<br>\n";
      if ($nbreLignes > NBRE_MAX_LIGNES)
        echo "<a href='?server=$id&action=$_GET[action]&startPosition=$no'>",
              "suivant ($no / ",$mds->numberOfRecordsMatched(),")</a><br>\n";
      die();
    }
    case 'viewRecord': {
      $fmt = $_GET['fmt'] ?? 'iso-yaml';
      $menu = HTML_HEADER;
      $url = "?server=$id&action=viewRecord&id=$_GET[id]&startPosition=$_GET[startPosition]";
      $menu .= "<table border=1><tr>";
      foreach (['iso-yaml','iso-xml','dcat-ttl','dcat-xml','double'] as $f) {
        if ($f == $fmt)
          $menu .= "<td>$f</td>";
        else
          $menu .= "<td><a href='$url&fmt=$f'>$f</a></td>";
      }
      $menu .= "<td><a href='?server=$id&action=listDatasets&startPosition=$_GET[startPosition]' target='_parent'>^</a></td>";
      $menu .= "</table>\n";
      
      switch ($fmt) {
        case 'iso-yaml': {
          echo $menu;
          $xml = $server->getRecordById('gmd', 'full', $_GET['id']);
          $record = IsoMd::convert($xml);
          echo '<pre>',YamlDump($record, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
          die();
        }
        case 'iso-xml': {
          if (0) {
            echo $menu;
            $xml = $server->getRecordById('gmd', 'full', $_GET['id']);
            echo "<pre>",str_replace('<','&lt;', $xml);
          }
          else {
            $url = $server->getRecordByIdUrl('gmd', 'full', $_GET['id']);
            header('HTTP/1.1 302 Moved Temporarily');
            header("Location: $url");
          }
          die();
        }
        case 'dcat-ttl': {
          echo $menu;
          $url = $server->getRecordByIdUrl('dcat', 'full', $_GET['id']);
          $xml = $server->getRecordById('dcat', 'full', $_GET['id']);
          $xml2 = preg_replace('!<csw:GetRecordByIdResponse [^>]*>!', '', $xml);
          $xml2 = preg_replace('!</csw:GetRecordByIdResponse>!', '', $xml2);
          //echo "<pre>",str_replace('<','&lt;', $xml2);
          $rdf = new \EasyRdf\Graph($url);
          $rdf->parse($xml2, 'rdf', $url);
          $turtle = $rdf->serialise('turtle');
          //echo "<pre>",str_replace('<', '&lt;', $turtle),"</pre>\n";
          echo "<pre>",Turtle::html($turtle),"</pre>\n";
          die();
        }
        case 'dcat-xml': {
          if (0) {
            echo $menu;
            $url = $server->getRecordByIdUrl('dcat', 'full', $_GET['id']);
            $xml = $server->getRecordById('dcat', 'full', $_GET['id']);
            //$xml = preg_replace('!<csw:GetRecordByIdResponse [^>]*>!', '', $xml);
            //$xml = preg_replace('!</csw:GetRecordByIdResponse>!', '', $xml);
            echo "<pre>",str_replace('<','&lt;', $xml);
            echo "<a href='$url'>Visualisation directe</a><br>\n";
          }
          else {
            $url = $server->getRecordByIdUrl('dcat', 'full', $_GET['id']);
            header('HTTP/1.1 302 Moved Temporarily');
            header("Location: $url");
          }
          die();
        }
        case 'double': {
          echo "
    <frameset cols='50%,50%' >
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]&startPosition=$_GET[startPosition]' name='left'>
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]&fmt=dcat-ttl&startPosition=$_GET[startPosition]' name='right'>
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
          $url = $rdfServer->rdfSearchUrl();
          $xml = $rdfServer->rdfSearch();
          $rdf = new \EasyRdf\Graph($url);
          $rdf->parse($xml, 'rdf', $url);
          $turtle = $rdf->serialise('turtle');
          echo "<pre>",Turtle::html($turtle),"</pre>\n";
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
    default: die("Action $_GET[action] inconnue");
  }
}
