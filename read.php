<?php
/** Lecture des MD d'un serveur CSW en ISO ou en DCAT.
 *
 * La classe CswServer implémente les méthodees d'appel à un serveur CSW.
 * Elle utilise un dictionnaire de serveurs définis dans le fichier servers.yaml.
 * La classe MDs implémente un itérateur sur GetRecords afin d'afficher une liste de métadonnées
 * plus indépendamment des appels GetRecords.
 * La classe Cache implémente un cache pour les appels Http effectués par le serveur CSW.
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';
require_once __DIR__.'/isomd.inc.php';

use Symfony\Component\Yaml\Yaml;

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
 * @param array<string,mixed> $array */
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
if (0) { // @phpstan-ignore-line // Test arrayToXml()
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

/** Manipulation de code Turtle */
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
    
/** Gestion d'un cache pour des requêtes http.
 *
 * get() effectue un appel Http sauf si le résultat est déjà en cache et dans ce cas renvoit le contenu du cache.
 * lastResultComesFromTheCache() indique si le dernier get provient du cache ou d'une requête Http.
 * lastHeaders() retourne les headers du dernier appel à Http::get()
 * lastCachepathReturned() fournit le chemin du dernier fichier de cache utilisé (en lecture ou en écriture)
 */
class Cache {
  readonly public string $cachedir; // répertoire de stockage des fichiers du cache, '' si pas de cache
  protected bool $lastResultComesFromTheCache = true; // indique si la dernière opération effectuée a utilisé ou non le cache
  /** @var list<string> $lastHeaders */
  protected array $lastHeaders = []; // headers du dernier appel à Http::get()
  protected string $lastCachepathReturned = ''; // conserve le chemin du dernier fichier de cache utilisé
  
  function lastResultComesFromTheCache(): bool { return $this->lastResultComesFromTheCache; }
  /** @return list<string> $lastHeaders */
  function lastHeaders(): array { return $this->lastHeaders; }
  function lastCachepathReturned(): string { return $this->lastCachepathReturned; }

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
  
  /** Retrouve le document correspondant à l'URL et aux options en utilisaant le cache.
   *
   * Les options sont celles définies pour Http::call()
   *
   * Objectifs:
   *  1) fusionner Cache::get() et Cache::request()
   *  2) retourner le body en string car le retour [headers, body] est compliqué à gérer pour l'appellant
   *  3) possibilité de conserver le dernier headers retourné en variable statique de la classe Http
   *  4) n'utiliser pour définir la clé de cache que les options qui impactent le contenu de la réponse
   *    - par exemple le content en POST impacte le retour
   *    - par contre max-retries n'impacte pas le contenu du retour
   *
   * 4 cas de figure:
   *  1) pas de cache => retourne Http::call()
   *  2) doc en cache => retourne le doc en cache
   *  3) doc pas en cache & erreur => Http::call() retourne false et pas de mise en cache
   *  4) doc pas en cache & !erreur => Http::call(), mise en cache et retourne résultat
   *
   * @param array<string,string|int|number> $options; liste des options définies dans Http::all()
   */
  function get(string $url, array $options=[]): string|false {
    if (!$this->cachedir) { // si pas de cache
      $result = Http::call($url, $options);
      $this->lastResultComesFromTheCache = false;
      $this->lastHeaders = Http::$lastHeaders;
      return $result;
    }
    $docCache = []; // les éléments pour construire le md5
    // Les options qui peuvent avoir un impact sur le contenu du résultat retourné
    foreach ($options as $key => $value) {
      if (in_array($key, ['Accept','Accept-Language','content']))
        $docCache[$key] = $value;
    }
    if (!$docCache) { // si aucune option n'est concerné o  n'utilise que l'url pour définir la clé de cache
      $cachepath = $this->cachedir.'/'.md5($url).'.xml';
      // permet ainsi de garder une compatibilité avec la ersion précédente
    }
    else {
      $docCache['cswUrl'] = $url; // l'URL a un impact ur le contenu du résultat retourné 
      $cachepath = $this->cachedir.'/'.md5(json_encode($docCache)).'.xml';
    }
    if (is_file($cachepath)) { // si l'URL est en cache
      $this->lastResultComesFromTheCache = true;
      $this->lastCachepathReturned = $cachepath;
      return file_get_contents($cachepath);
    }
    // sinon appel Http
    $this->lastResultComesFromTheCache = false;
    $contents = Http::call($url, $options);
    $this->lastHeaders = Http::$lastHeaders;
    if ($contents === false)
      return false;
    file_put_contents($cachepath, $contents);
    $this->lastCachepathReturned = $cachepath;
    return $contents;
  }

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
  
  /** dictionnaire des caractéristiques des serveurs .
   * @var array<string,mixed> */
  static array $servers;
  readonly public string $serverId;
  readonly public string $cswUrl;
  readonly public string $title;
  /** @var array<mixed> */
  readonly public array $post;
  /** @var array<string,string|int|number> $httpOptions */
  readonly public array $httpOptions;
  readonly public Cache $cache;
  
  /** Retourne le dictionnaire des caractéristiques des serveurs.
   * @return array<string,mixed> */
  static function servers(): array {
    if (!isset(self::$servers))
      self::$servers = Yaml::parseFile(__DIR__.'/servers.yaml')['servers'];
    return self::$servers;
  }
  
  /** Retourne les caractéritiques du serveur s'il existe et sinon null.
   * @return array<string,mixed>|null */
  static function exists(string $serverId): ?array { return self::servers()[$serverId] ?? null; }
  
  function __construct(string $serverId, string $cachedir) {
    if (!($server = self::exists($serverId)))
      throw new Exception("Erreur, serveur $serverId inexistant");
    $this->serverId = $serverId;
    $this->cswUrl = $server['cswUrl'];
    $this->title = $server['title'];
    $this->post = $server['post'] ?? [];
    $this->httpOptions = $server['httpOptions'] ?? [];
    $this->cache = new Cache($cachedir);
  }
  
  /** Retourne l'URL du GetCapabilities */
  function getCapabilitiesUrl(): string {
    return $this->cswUrl.'?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetCapabilities';
  }
  
  /** Retourne le document du GetCapabilities en utilisant le cache */
  function getCapabilities(): string {
    $result = $this->cache->get($this->getCapabilitiesUrl(), $this->httpOptions);
    if ($result === false)
      throw new Exception("Erreur dans l'appel de ".$this->getCapabilitiesUrl());
    return $result;
  }
  
  /** Retourne l'URL du GetRecords en GET */
  function getRecordsUrl(string $type, string $ElementSetName, int $startPosition): string {
    $OutputSchema = urlencode(self::GETRECORDS_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::GETRECORDS_PARAMS[$type]['namespace']);
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];

    return self::servers()[$this->serverId]['cswUrl']
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecords&ElementSetName=$ElementSetName"
      .'&ResultType=results&MaxRecords=10&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&startPosition=$startPosition";
  }
  
  /** Réalise un GetRecords soit en GET soit en POST en fonction du paramétrage du serveur */
  function getRecords(string $type, string $ElementSetName, int $startPosition): string {
    if ($this->post)
      $result = $this->getRecordsInPost($type, $ElementSetName, $startPosition);
    else
      $result = $this->getRecordsInGet($type, $ElementSetName, $startPosition);
    //var_dump($result);
    if ($result === false)
      throw new Exception("Erreur dans l'appel de getRecords");
    return $result;
  }
  
  /** Réalise un GetRecords en GET */
  private function getRecordsInGet(string $type, string $ElementSetName, int $startPosition): string|false {
    //echo "CswServer::getRecords(startPosition=$startPosition)<br>\n";
    $url = $this->getRecordsUrl($type, $ElementSetName, $startPosition);
    return $this->cache->get($url, $this->httpOptions);
  }
  
  /** Réalise un GetRecords en POST */
  private function getRecordsInPost(string $type, string $ElementSetName, int $startPosition): string|false {
    $OutputSchema = self::GETRECORDS_PARAMS[$type]['OutputSchema'];
    $namespace = self::GETRECORDS_PARAMS[$type]['namespace'];
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];
    
    $filter = $this->post['filter'] ?? [];
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
      
    //echo "getRecordsInPost(query=",str_replace('<','&lt;',$query),")<br>\n";
    $httpOptions = array_merge($this->httpOptions, [
        'method'=> 'POST',
        'Content-Type'=> 'application/xml',
        'content'=> $query,
      ]);
    $result = $this->cache->get($this->cswUrl, $httpOptions);
    /*echo "<pre>";
    if (!$this->cache->lastResultComesFromTheCache()) {
      echo "headers="; print_r($this->cache->lastHeaders());
    }
    echo "result=",str_replace('<','&lt;', $result),"</pre>\n"; */
    return $result;
  }
  
  /** Retourne l'URL d'un GetRecordById */
  function getRecordByIdUrl(string $type, string $ElementSetName, string $id): string {
    $OutputSchema = urlencode(self::GETRECORDS_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::GETRECORDS_PARAMS[$type]['namespace']);
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];
    return $this->cswUrl
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById&ElementSetName=$ElementSetName"
      .'&ResultType=results&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&id=".$id;
  }
  
  /** Retourne le GetRecordById */
  function getRecordById(string $type, string $ElementSetName, string $id): string {
    return $this->cache->get($this->getRecordByIdUrl($type, $ElementSetName, $id), $this->httpOptions);
  }

  /** suppression du cache */
  function clearCache(): void { $this->cache->clear(); }
  
  /** attente du délai défini s'il est défini et si la dernière opération n'était pas en cache.
   *
   * L'attente est destinée à ne pas stresser le catalogue moissoné.
   */
  function sleep(): void {
    //echo "sleep()\n";
    if ($this->cache->lastResultComesFromTheCache())
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

/** Itérateur sur les métadonnées DC brief.
 * La clé (TKey) est la postion dans le GetRecords, La valeur (TValue) est un SimpleXMLElement,
 * @implements \Iterator<int,SimpleXMLElement> */
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
  private function getBuffer(): void {
    $records = $this->server->getRecords('dc', 'brief', $this->firstPos);
    $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
    $this->records = new SimpleXMLElement($records);
    $this->numberOfRecordsMatched = (int)$this->records->csw_SearchResults['numberOfRecordsMatched'];
    $this->nextRecord = (int)$this->records->csw_SearchResults['nextRecord'];
  }
  
  function rewind(): void {
    //echo "rewind()<br>\n";
    $this->currentPos = $this->startPos;
    $this->firstPos = (int)floor(($this->startPos - 1) / 10) * 10 + 1;
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
        throw new \Exception("Erreur firstPos==0");
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
  
  static function exists(string $serverId): bool { return isset(CswServer::exists($serverId)['rdfSearchUrl']); }
    
  function __construct(string $serverId, string $cachedir) {
    if (!self::exists($serverId))
      throw new Exception("Erreur, serveur $serverId inexistant");
    $this->serverId = $serverId;
    $this->cache = new Cache("rdf-$cachedir");
    $this->title = CswServer::exists($serverId)['title'];
  }
  
  function rdfSearchUrl(): ?string { return CswServer::exists($this->serverId)['rdfSearchUrl'] ?? null; }

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
      echo "<li><a href='",$server->getCapabilitiesUrl(),"'>Lien vers les GetCapabilities</a></li>\n";
      echo "<li><a href='?server=$id&action=GetCapabilities'>GetCapabilities@cache</a></li>\n";
      echo "<li><a href='?server=$id&action=GetRecords'>GetRecords sans utiliser MDs</a></li>\n";
      echo "<li><a href='?server=$id&action=listDatasets'>Liste des dataset (en utilisant MDs)</a></li>\n";
      if ($rdfServer) {
        echo "<li><a href='",$rdfServer->rdfSearchUrl(),"'>Lien vers le point rdf.search</a></li>\n";
        echo "<li><a href='?server=$id&action=rdf'>Affichage du RDF en Turtle</a></li>\n";
      }
      if (isset(CswServer::servers()[$id]['ogcApiRecordsUrl'])) {
        $url = CswServer::servers()[$id]['ogcApiRecordsUrl'];
        echo "<li><a href='$url'>ogcApiRecordsUrl</a></li>\n";
      }
      echo "</ul><a href='?'>Retour à la liste des catalogues.</a></p>\n";
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
      $no = 0;
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
              "suivant ($no / ",$mds->numberOfRecordsMatched(),")</a> / \n";
      echo "<a href='?server=$id&action=listDatasets'>Retour au 1er</a> / \n";
      echo "<a href='?server=$id'>Retour à l'accueil</a></p>";
      die();
    }
    case 'viewRecord': {
      $fmt = $_GET['fmt'] ?? 'iso-yaml';
      $menu = HTML_HEADER;
      $url = "?server=$id&action=viewRecord&id=$_GET[id]&startPosition=$_GET[startPosition]";
      $menu .= "<table border=1><tr>";
      foreach (['iso-yaml','iso-xml','dcat-ttl','dcat-yamlld-c','dcat-xml','double'] as $f) {
        if ($f == $fmt)
          $menu .= "<td><b>$f</b></td>";
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
          if (0) { // @phpstan-ignore-line
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
          try {
            $rdf->parse($xml2, 'rdf', $url);
          }
          catch (EasyRdf\Parser\Exception $e) {
            die("Erreur sur le parse RDF: ".$e->getMessage());
          }
          $turtle = $rdf->serialise('turtle');
          //echo "<pre>",str_replace('<', '&lt;', $turtle),"</pre>\n";
          echo "<pre>",Turtle::html($turtle),"</pre>\n";
          die();
        }
        case 'dcat-yamlld-c': { // Yaml-LD compacté avec le contexte context.yaml
          echo $menu;
          //echo '<pre>'; print_r(\EasyRdf\Format::getFormats());
          
          $url = $server->getRecordByIdUrl('dcat', 'full', $_GET['id']);
          $xml = $server->getRecordById('dcat', 'full', $_GET['id']);
          $xml2 = preg_replace('!<csw:GetRecordByIdResponse [^>]*>!', '', $xml);
          $xml2 = preg_replace('!</csw:GetRecordByIdResponse>!', '', $xml2);
          //echo "<pre>",str_replace('<','&lt;', $xml2);
          $rdf = new \EasyRdf\Graph($url);
          try {
            $rdf->parse($xml2, 'rdf', $url);
          }
          catch (EasyRdf\Parser\Exception $e) {
            die("Erreur sur le parse RDF: ".$e->getMessage());
          }
          if (0) { // @phpstan-ignore-line // affichage du JSON-LD
            $jsonld = json_decode($rdf->serialise('jsonld'), true);
            echo "<pre>",Yaml::dump($jsonld),"</pre>\n";
            die();
          }
          $compacted = ML\JsonLD\JsonLD::compact(
            $rdf->serialise('jsonld'),
            json_encode(Yaml::parseFile(__DIR__.'/context.yaml')));
          $compacted = json_decode(json_encode($compacted), true);
          $compacted['@context'] = 'https://geoapi.fr/gndcat/context.yaml';
          echo "<pre>",YamlDump($compacted, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"</pre>\n";
          die();
        }
        case 'dcat-xml': {
          if (0) { // @phpstan-ignore-line
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
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]&fmt=dcat-yamlld-c&startPosition=$_GET[startPosition]' name='right'>
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
