<?php
/** Interrogation d'un serveur de métadonnées.
 * - La classe MdServer définit une abstraction d'un serveur CSW par un itérateur de MDs et des méthodes
 *   complémentaires sur la MD courante
 * - La classe CswServer facilite l'utilisation d'un serveur CSW en construisant les URL des requêtes CSW
 *   et en effectuant les requêtes au travers du cache associé au serveur.
 * - La classe Cache gère un cache des requêtes Http de manière sommaire.
 */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';

use Symfony\Component\Yaml\Yaml;

/** Traduit un filtre défini en array en XML avec l'espace de nom ogc.
 * @param array<mixed> $array */
function arrayToXml(array $array): string {
  $xml = '';
  if (array_is_list($array)) {
    foreach ($array as $value) {
      $xml .= arrayToXml($value);
    }
  }
  else {
    foreach ($array as $key => $value) {
      if (!is_array($value)) {
        $xml .= "<ogc:$key>$value</ogc:$key>";
      }
      elseif ($key == 'Filter') {
        $xml .= "<ogc:Filter xmlns:ogc='http://www.opengis.net/ogc'>".arrayToXml($value)."</ogc:$key>";
      }
      else
        $xml .= "<ogc:$key>".arrayToXml($value)."</ogc:$key>";
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


/** Gestion d'un cache pour des requêtes http.
 *
 * get() effectue un appel Http sauf si le résultat est déjà en cache et dans ce cas renvoit le contenu du cache.
 * lastResultComesFromTheCache() indique si le dernier get provient du cache ou d'une requête Http.
 * lastHeaders() retourne les headers du dernier appel à Http::get()
 * lastCachepathReturned() fournit le chemin du dernier fichier de cache utilisé (en lecture ou en écriture)
 */
class Cache {
  readonly public string $cachedir; // répertoire de stockage des fichiers du cache, '' si pas de cache
  readonly public string $fileExt; // extension à utiliser pour les fichiers du cache
  protected bool $lastResultComesFromTheCache = true; // indique si la dernière opération effectuée a utilisé ou non le cache
  /** @var list<string> $lastHeaders */
  protected array $lastHeaders = []; // headers du dernier appel à Http::get()
  protected string $lastCachepathReturned = ''; // conserve le chemin du dernier fichier de cache utilisé
  
  function lastResultComesFromTheCache(): bool { return $this->lastResultComesFromTheCache; }
  /** @return list<string> $lastHeaders */
  function lastHeaders(): array { return $this->lastHeaders; }
  function lastCachepathReturned(): string { return $this->lastCachepathReturned; }

  function __construct(string $cachedir, string $fileExt='.xml') {
    $this->fileExt = $fileExt;
    
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
    
    if (!$docCache) { // si aucune option n'est concerné on n'utilise que l'url pour définir la clé de cache
      $cachepath = $this->cachedir.'/'.md5($url).$this->fileExt;
      // permet ainsi de garder une compatibilité avec la ersion précédente
    }
    else {
      $docCache['cswUrl'] = $url; // l'URL a un impact sur le contenu du résultat retourné 
      $cachepath = $this->cachedir.'/'.md5(json_encode($docCache)).$this->fileExt;
    }
    if (is_file($cachepath)) { // si l'URL est en cache
      $this->lastResultComesFromTheCache = true;
      $this->lastCachepathReturned = $cachepath;
      return file_get_contents($cachepath);
    }
    
    // sinon appel Http
    if (1) { // @phpstan-ignore-line
      echo "<pre>document pas en cache:\n";
      if ($docCache)
        echo "  docCache=",json_encode($docCache),"\n";
      else
        echo "  url=$url\n";
      echo "  cachePath=$cachepath\n";
      echo "</pre>\n";
    }
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

/** classe portant le registre des serveurs */
class Server {
  /** dictionnaire des caractéristiques des serveurs .
   * @var array<string,mixed> */
  static array $servers;
  
  /** génère un dictionnaire de serveurs à la place des définitions de groupe générique.
   * @param array<string,mixed> $servers // le dictionnaire en entrée contenant possiblement des groupes généras
   * @return array<string,mixed> // le dictionnaire en sortie dans lequel les groupes générés le sont
   */
  static function generate(array $servers): array {
    foreach ($servers as $id => &$server) {
      if (isset($server['vars'])) {
        $vars = $server['vars'];
        unset($server['vars']);
        $patternYaml = Yaml::dump($server['pattern']);
        unset($server['pattern']);
        $sservers = [];
        foreach ($vars as $k => $v) {
          $patternY = str_replace(['$key', '$value'], [$k, $v], $patternYaml);
          $pattern = Yaml::parse($patternY);
          //echo '<pre>'; print_r($pattern); echo '</pre>';
          $sservers = array_merge($sservers, $pattern);
        }
        $server['servers'] = $sservers;
      }
    }
    return $servers;
  }
  
  /** Retourne le dictionnaire des caractéristiques des serveurs.
   * @return array<string,mixed> */
  static function servers(): array {
    if (!isset(self::$servers))
      self::$servers = self::generate(Yaml::parseFile(__DIR__.'/servers.yaml')['servers']);
    //echo "<pre>",Yaml::dump(self::$servers, 9, 2); die();
    return self::$servers;
  }
  
  /** Retourne les caractéritiques du serveur s'il existe et sinon null.
   * $serverId peut être un chemin constitué de groupes de serveeurs se terminant par un id d serveur.
   * @param array<string,mixed>|null $servers dictionnaire des serveurs, initialement le dictionnaire complet puis sous-dict.
   * @return array<string,mixed>|null */
  static function exists(string $serverId, array $servers=null): ?array {
    //echo "CswServerexists($serverId)<br>\n";
    $serverIds = explode('/', $serverId);
    if (!$servers)
      $servers = self::servers();
    if (count($serverIds) == 1)
      return $servers[$serverId] ?? null;
    $groupeId = array_shift($serverIds);
    return self::exists(implode('/', $serverIds), $servers[$groupeId]['servers']);
  }
};

/** Facilite l'utilisation d'un serveur CSW en construisant les URL des requêtes CSW et en effectuant les requêtes
 * au travers du cache associé au serveur.
 */
class CswServer {
  /** Paramètres du GetRecords et GetRecordById en fonction du modèle de métadonnées */
  const MODEL_PARAMS = [
    'dc'=> [
      'OutputSchema' => 'http://www.opengis.net/cat/csw/2.0.2',
      'namespace' => 'xmlns(csw=http://www.opengis.net/cat/csw)',
      'TypeNames' => 'csw:Record',
    ], // Dublin Core
    'gmd'=> [
      'OutputSchema' => 'http://www.isotc211.org/2005/gmd',
      'namespace' => 'xmlns(gmd=http://www.isotc211.org/2005/gmd)',
      'TypeNames' => 'gmd:MD_Metadata',
    ], // ISO 19115/19139
    'mdb'=> [
      'OutputSchema' => 'http://standards.iso.org/iso/19115/-3/mdb/2.0',
      'namespace' => 'xmlns(mdb=http://standards.iso.org/iso/19115/-3/mdb/2.0)',
      'TypeNames' => 'mdb:MD_Metadata',
    ], // Metadata Base (MDB) ??
    'dcat'=> [
      'OutputSchema' => 'http://www.w3.org/ns/dcat#',
      'namespace' => 'xmlns(dcat=http://www.w3.org/ns/dcat#)',
      'TypeNames' => 'dcat',
    ], // DCAT
    'dcat-ap'=> [
      'OutputSchema' => 'http://data.europa.eu/930/',
      'namespace' => 'xmlns(dcat-ap=http://data.europa.eu/930/)',
      'TypeNames' => 'dcat-ap',
    ], // DCAT-AP ???
    'gfc'=> [
      'OutputSchema' => 'http://www.isotc211.org/2005/gfc',
      'namespace' => 'xmlns(gfc=http://www.isotc211.org/2005/gfc)',
      'TypeNames' => 'gfc:FC_FeatureCatalogue',
    ], // FeatureCatalogue
  ];
  
  readonly public string $serverId;
  readonly public string $cswUrl;
  readonly public string $title;
  readonly public bool $post;
  /** @var array<mixed> $filter */
  readonly public array $filter;
  /** @var array<string,string|int|number> $httpOptions */
  readonly public array $httpOptions;
  readonly public Cache $cache;
  
  function __construct(string $serverId, string $cachedir) {
    if (!($server = Server::exists($serverId)))
      throw new Exception("Erreur, serveur $serverId inexistant");
    $this->serverId = $serverId;
    $this->cswUrl = $server['cswUrl'];
    $this->title = $server['title'];
    $this->post = $server['cswPost'] ?? false;
    $this->filter = $server['filter'] ?? [];
    $this->httpOptions = $server['httpOptions'] ?? [];
    $this->cache = new Cache(str_replace('/','-',$cachedir));
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
  
  /** Retourne l'URL du GetRecords en GET.
   * @param array<mixed>|null $filter
   */
  function getRecordsUrl(string $type, string $ElementSetName, int $startPosition, ?array $filter=null): string {
    $OutputSchema = urlencode(self::MODEL_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::MODEL_PARAMS[$type]['namespace']);
    $TypeNames = self::MODEL_PARAMS[$type]['TypeNames'];
    if ($filter === null)
      $filter = $this->filter;
    
    return $this->cswUrl
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecords&ElementSetName=$ElementSetName"
      .'&ResultType=results&MaxRecords=10&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&startPosition=$startPosition"
      .($filter ? '&CONSTRAINTLANGUAGE=FILTER&CONSTRAINT_LANGUAGE_VERSION=1.1.0&CONSTRAINT='.urlencode(arrayToXml($filter)) : '');
  }
  
  /** Réalise un GetRecords soit en GET soit en POST en fonction du paramétrage du serveur.
   * Par défaut, $filter=null <=> utilisation du filtre défini pour le serveur.
   * Pour une requête sans filtre utiliser [].
   * @param array<mixed>|null $filter
   */
  function getRecords(string $type, string $ElementSetName, int $startPosition, ?array $filter=null): string {
    if ($this->post)
      $result = $this->getRecordsInPost($type, $ElementSetName, $startPosition, $filter);
    else
      $result = $this->getRecordsInGet($type, $ElementSetName, $startPosition, $filter);
    //var_dump($result);
    if ($result === false)
      throw new Exception("Erreur dans l'appel de getRecords");
    if ($result === '') {
      if ($this->cache->lastResultComesFromTheCache())
        throw new Exception("Erreur GetRecords retourne une chaine vide provenant de "
          .$this->cache->lastCachepathReturned());
      else
        throw new Exception("Erreur GetRecords retourne une chaine vide ne provenant pas du cache");
    }
    return $result;
  }
  
  /** Réalise un GetRecords en GET
   * @param array<mixed>|null $filter
   */
  private function getRecordsInGet(string $type, string $ElementSetName, int $startPosition, ?array $filter=null): string|false {
    //echo "CswServer::getRecords(startPosition=$startPosition)<br>\n";
    $url = $this->getRecordsUrl($type, $ElementSetName, $startPosition, $filter);
    return $this->cache->get($url, $this->httpOptions);
  }
  
  /** Réalise un GetRecords en POST
   * @param array<mixed>|null $filter
   */
  private function getRecordsInPost(string $type, string $ElementSetName, int $startPosition, ?array $filter=null): string|false {
    $OutputSchema = self::MODEL_PARAMS[$type]['OutputSchema'];
    $namespace = self::MODEL_PARAMS[$type]['namespace'];
    $TypeNames = self::MODEL_PARAMS[$type]['TypeNames'];
    
    if ($filter === null)
      $filter = $this->filter;
    
    $query = '<?xml version="1.0" encoding="utf-8"?>'."\n"
      ."<GetRecords service='CSW' version='2.0.2' maxRecords='10'\n"
      ."  startPosition='$startPosition' resultType='results' outputFormat='application/xml'\n"
      ."  outputSchema='$OutputSchema' xmlns='http://www.opengis.net/cat/csw/2.0.2'\n"
      ."  xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance'\n"
      ."  xsi:schemaLocation='http://schemas.opengis.net/csw/2.0.2/CSW-discovery.xsd'>\n"
      ."  <Query typeNames='$TypeNames'>\n"
      ."    <ElementSetName>$ElementSetName</ElementSetName>\n"
      .($filter ? "<Constraint version='1.1.0'>".arrayToXml($filter).'</Constraint>' : '')
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
    $OutputSchema = urlencode(self::MODEL_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::MODEL_PARAMS[$type]['namespace']);
    $TypeNames = self::MODEL_PARAMS[$type]['TypeNames'];
    return $this->cswUrl
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById&ElementSetName=$ElementSetName"
      .'&ResultType=results&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&id=".urlencode($id);
  }
  
  /** Retourne le GetRecordById comme chaine XML */
  function getRecordById(string $type, string $ElementSetName, string $id): string {
    return $this->cache->get($this->getRecordByIdUrl($type, $ElementSetName, $id), $this->httpOptions);
  }

  /** Retourne le GetRecordById en Full DCAT comme \EasyRdf\Graph */
  function getFullDcatById(string $id): \EasyRdf\Graph {
    $url = $this->getRecordByIdUrl('dcat', 'full', $id);
    $xml = $this->getRecordById('dcat', 'full', $id);
    $xml = preg_replace('!<csw:GetRecordByIdResponse [^>]*>!', '', $xml);
    $xml = preg_replace('!</csw:GetRecordByIdResponse>!', '', $xml);
    $rdf = new \EasyRdf\Graph($url);
    try {
      $rdf->parse($xml, 'rdf', $url);
    }
    catch (EasyRdf\Parser\Exception $e) {
      die("Erreur sur le parse RDF: ".$e->getMessage());
    }
    return $rdf;
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
    $delay = Server::exists($this->serverId)['delay'] ?? 0;
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

/** Abstraction d'un CswServer par un itérateur de MDs et des méthodes complémentaires sur la MD courante.
 * La classe définit au dessus de la classe CswServer:
 *  - un itérateur sur les fiches de métadonnées en DC brief,
 *  - 2 méthodes retournant la fiche courante complète:
 *    - getFullGmd()  en XML ISO 19139
 *    - getFullDcat() en DCAT en \EasyRdf\Graph
 * La clé (TKey) de l'itérateur est la position dans le GetRecords, La valeur (TValue) est le SimpleXMLElement
 * de la fiche courante définie en DublinCore/brief,
 *
 * @implements \Iterator<int,SimpleXMLElement> */
class MdServer implements Iterator {
  readonly public CswServer $server; // Serveur CSW sous-jacent
  readonly public int $startPos; // position de démarrage définie à la création
  //protected string $type;
  protected int $firstPos; // première position du buffer courant
  protected ?SimpleXMLElement $records; // tableau des enregistrements courants ou null
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
    //echo "getBuffer - firstPos=$this->firstPos<br>\n";
    if ($this->firstPos == 0) {
      $this->records = null;
      //echo "<pre>"; print_r($this);
      return;
    }
    $records = $this->server->getRecords('dc', 'brief', $this->firstPos);
    if (!$records) {
      throw new Exception("Erreur getRecords() retourne une chaine vide");
    }
    $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
    $this->records = new SimpleXMLElement($records);
    if ($this->records->Exception) {
      throw new Exception("Exception retournée: ".$this->records->Exception->ExceptionText);
    }
    $this->numberOfRecordsMatched = (int)$this->records->csw_SearchResults['numberOfRecordsMatched'];
    $this->nextRecord = (int)$this->records->csw_SearchResults['nextRecord'];
    //echo "numberOfRecordsMatched=$this->numberOfRecordsMatched<br>\n";
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
      $this->getBuffer();
    }
  }
  
  function valid(): bool {
    //echo "valid()<br>\n";
    return $this->records && ($this->currentPos <= $this->numberOfRecordsMatched);
  }
  
  /** Retourne la fiche de MD courante complète en XML ISO 19139 */
  function getFullGmd(): string {
    return $this->server->getRecordById('gmd', 'full', $this->current()->dc_identifier);
  }
  
  /** Retourne la fiche courante complète de MD en DCAT comme \EsayRdf\Graph */
  function getFullDcat(): \EasyRdf\Graph {
    return $this->server->getFullDcatById($this->current()->dc_identifier);
  }
};
