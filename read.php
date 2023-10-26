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

const VERSION = "25/10/2023";

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

/** Charge le fichier FILE_PATH  et l'utilise pour standardiser les noms des organismes */
class OrgRef {
  const FILE_PATH = __DIR__.'/orgref.yaml';
  static array $ref=[]; // [(altLabel|prefLabel) -> prefLabel]
  
  static function init(): void {
    if (is_file(self::FILE_PATH))
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
  
  function __construct(string $serverId, string $cachedir) {
    if (!($server = self::exists($serverId)))
      throw new Exception("Erreur, serveur $serverId inexistant");
    $this->serverId = $serverId;
    $this->cswUrl = $server['cswUrl'];
    $this->title = $server['title'];
    $this->post = $server['post'] ?? [];
    
    /*$filter = $this->post['filter'] ?? [];
    $filreXml = "<Constraint version='1.1.0'>".arrayToXml($filter).'</Constraint>';
    header('Content-type: application/xml');
    die($filreXml);*/
    
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
  
  /** Retourne l'URL du GetRecords en GET */
  function getRecordsUrl(string $type, string $ElementSetName, int $startPosition): string {
    $OutputSchema = urlencode(self::MODEL_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::MODEL_PARAMS[$type]['namespace']);
    $TypeNames = self::MODEL_PARAMS[$type]['TypeNames'];

    return $this->cswUrl
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
    if ($result === '') {
      if ($this->cache->lastResultComesFromTheCache())
        throw new Exception("Erreur GetRecords retourne une chaine vide provenant de "
          .$this->cache->lastCachepathReturned());
      else
        throw new Exception("Erreur GetRecords retourne une chaine vide ne provenant pas du cache");
    }
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
    $OutputSchema = self::MODEL_PARAMS[$type]['OutputSchema'];
    $namespace = self::MODEL_PARAMS[$type]['namespace'];
    $TypeNames = self::MODEL_PARAMS[$type]['TypeNames'];
    
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
    $OutputSchema = urlencode(self::MODEL_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::MODEL_PARAMS[$type]['namespace']);
    $TypeNames = self::MODEL_PARAMS[$type]['TypeNames'];
    return $this->cswUrl
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById&ElementSetName=$ElementSetName"
      .'&ResultType=results&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&id=".$id;
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
    $delay = self::exists($this->serverId)['delay'] ?? 0;
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

/** Manipulation de code Turtle */
class Turtle {
  readonly public string $turtle; // le code Turtle
  
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

/** Manipulation de Yaml-LD */
class YamlLD {
  readonly public array $array; // stockage comme array
  
  /** construit un objet soit à partir d'une représentation array JSON soit à partir d'un objet \EasyRdf\Graph */
  function __construct(\EasyRdf\Graph|array $rdfOrArray) {
    if (is_array($rdfOrArray))
      $this->array = $rdfOrArray;
    else
      $this->array = json_decode($rdfOrArray->serialise('jsonld'), true);
    // die ("<pre>$this</pre>\n"); // affichage du JSON-LD
  }
  
  function __toString(): string { return YamlDump($this->array, 9, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); }
  
  function improve(): self {
    
  }
  
  function compact(): self {
    $compacted = ML\JsonLD\JsonLD::compact(
      json_encode($this->array),
      json_encode(Yaml::parseFile(__DIR__.'/contextnl.yaml')));
    $compacted = json_decode(json_encode($compacted), true);
    $compacted['@context'] = 'https://geoapi.fr/gndcat/contextnl.yaml';
    //$compacted = $this->improve()
    return new self($compacted);
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

  function rdfSearch(): \EasyRdf\Graph {
    $url = $this->rdfSearchUrl();
    $xml = $this->cache->get($url);
    $rdf = new \EasyRdf\Graph($url);
    $rdf->parse($xml, 'rdf', $url);
    return $rdf;
  }
};

/** Balaie le catalogue indiqué et retourne un array [responsibleParty.name][dataset.id] => 1 */
function responsibleParties(string $id, string $cacheDir, callable $stdOrgName): array {
  $mds = new MDs($id, $cacheDir, 1);
  $server = new CswServer($id, $cacheDir);
  foreach ($mds as $no => $record) {
    if (in_array($record->dc_type, ['FeatureCatalogue','service'])) continue;
    //echo " - $record->dc_title ($record->dc_type)\n";
    //echo "$no / ",$mds->numberOfRecordsMatched(),"\n";
    $xml = $server->getRecordById('gmd', 'full', (string)$record->dc_identifier);
    $data = IsoMd::convert($xml);
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
        case 'listMDs': {
          foreach (new MDs($id, $id, 1) as $no => $md) {
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
          $mds = new MDs($id, $id, 1);
          $mdDates = [];
          foreach ($mds as $no => $record) {
            if (in_array($record->dc_type, ['FeatureCatalogue','service'])) continue;
            //echo " - $record->dc_title ($record->dc_type)\n";
            //echo "$no / ",$mds->numberOfRecordsMatched(),"\n";
            $xml = $server->getRecordById('gmd', 'full', (string)$record->dc_identifier);
            $data = IsoMd::convert($xml);
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
        case 'mdQualStat' : { // affiche sur cheque fiche un indicateur de qualité
          $mds = new MDs($id, $id, 1);
          $maxQual = 0;
          foreach ($mds as $no => $brief) {
            if (in_array($brief->dc_type, ['FeatureCatalogue','service'])) continue;
            $fullgmd = $server->getRecordById('gmd', 'full', (string)$brief->dc_identifier);
            $fullgmd = IsoMd::convert($fullgmd);
            $qual = IsoMd::quality($fullgmd);
            if ($qual > $maxQual) {
              $maxQual = $qual;
              $bestId = (string)$brief->dc_identifier;
            }
          }
          echo "$bestId -> $maxQual\n";
          die("FIN\n");
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
    echo "</ul><a href='?server=error&action=doc'>doc</a><br>\n";
    echo "<a href='?server=error&action=misc'>divers</a></p>\n";
    echo "--<br>version: ",VERSION,"</p>\n";
    die();
  }

  $id = $_GET['server'];
  $server = CswServer::exists($id);
  if ($server && !isset($server['cswUrl'])) {
    echo HTML_HEADER,"<h2>Choix d'un des catalogues connus de \"$server[title]\"</h2><ul>\n";
    foreach ($server['servers'] as $id2 => $server) {
      echo "<li><a href='?server=$id/$id2'>$server[title]</a></li>\n";
    }
    echo "</ul>\n";
    die();
  }
  
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
      if (isset(CswServer::exists($id)['ogcApiRecordsUrl'])) {
        $url = CswServer::exists($id)['ogcApiRecordsUrl'];
        echo "<li><a href='$url' target='_blank'>Lien vers le point /collections OGC ApiRecords</a></li>\n";
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
      else
        echo "$startPosition -> ",$mds->numberOfRecordsMatched()," / \n";
      echo "<a href='?server=$id&action=listDatasets'>Retour au 1er</a> / \n";
      echo "<a href='?server=$id'>Retour à l'accueil</a></p>";
      die();
    }
    case 'viewRecord': {
      $fmt = $_GET['fmt'] ?? 'iso-yaml'; // modèle et format
      $menu = HTML_HEADER;
      $startPosition = isset($_GET['startPosition']) ? "&startPosition=$_GET[startPosition]" : '';
      $url = "?server=$id&action=viewRecord&id=$_GET[id]$startPosition";
      $menu .= "<table border=1><tr>";
      foreach ([
         'iso-yaml'=> 'ISO 19139 Inspire formatté en Yaml',
         'iso-xml'=> 'ISO 19139 complet formatté en XML',
         'dcat-ttl'=> 'DCAT formatté en Turtle',
         'dcat-yamlLd-c'=> "DCAT formatté en Yaml-LD compacté avec le contexte",
         'dcat-yamlLd'=> "DCAT formatté en Yaml-LD non compacté",
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
        case 'iso-yaml': { // ISO 19139 formatté en Yaml
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
          $rdf = $server->getFullDcatById($_GET['id']);
          $turtle = new Turtle($rdf);
          echo "<pre>$turtle</pre>\n";
          die();
        }
        case 'dcat-yamlLd': {
          echo $menu;
          $rdf = $server->getFullDcatById($_GET['id']);
          $yamlld = new YamlLD($rdf);
          //$compacted = $yamlld->compact();
          echo "<pre>$yamlld</pre>\n";
          die();
        }
        case 'dcat-yamlLd-c': { // Yaml-LD compacté avec le contexte contextnl.yaml
          echo $menu;
          //echo '<pre>'; print_r(\EasyRdf\Format::getFormats());
          $rdf = $server->getFullDcatById($_GET['id']);
          $yamlld = new YamlLD($rdf);
          $compacted = $yamlld->compact();
          echo "<pre>$compacted</pre>\n";
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
          $startPosition = isset($_GET['startPosition']) ? "&startPosition=$_GET[startPosition]" : '';
          echo "
    <frameset cols='50%,50%' >
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]$startPosition' name='left'>
      <frame src='?server=$id&action=viewRecord&id=$_GET[id]&fmt=dcat-yamlLd-c$startPosition' name='right'>
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
      echo "<li><a href='?server=gide/gn-pds&action=viewRecord",
            "&id=fr-120066022-ldd-4bc9b901-1e48-4afd-a01a-cc80e40c35b8&fmt=double'>",
            "Exemple de fiche de métadonnées Géo-IDE bien remplie</a></li>\n";
      echo "<li><a href='?server=gide/gn&action=idxRespParty&respParty=DDT%20de%20Charente'>",
            "Liste des JD de Géo-IDE GN ayant comme responsibleParty 'DDT de Charente'</a></li>\n";
            echo "</ul>\n";
      die();
    }
    default: die("Action $_GET[action] inconnue");
  }
}
