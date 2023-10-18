<?php
// Lecture basique des MD en CSW/DCAT

require_once __DIR__.'/http.inc.php';


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

/** Itérateur sur les métadonnées DC brief */
class MDs implements Iterator {
  protected CswServer $server;
  protected string $type;
  protected SimpleXMLElement $records;
  protected int $startPos; // position de démarrage définie à la création
  protected int $fisrtPos; // première position du buffer courant
  protected int $numberOfRecordsMatched;
  protected int $nextRecord;
  protected int $currentPos;
  
  function __construct(CswServer $server, int $startPosition) {
    $this->server = $server;
    $this->startPos = $startPosition;
  }
  
  function numberOfRecordsMatched(): int { return $this->numberOfRecordsMatched; }
  
  function rewind(): void {
    //echo "rewind()<br>\n";
    $this->currentPos = $this->startPos;
    $this->fisrtPos = floor(($this->startPos - 1) / 10) * 10 + 1;
    $records = $this->server->getRecords('dc', 'brief', $this->fisrtPos);
    $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
    $this->records = new SimpleXMLElement($records);
    $this->numberOfRecordsMatched = (int)$this->records->csw_SearchResults['numberOfRecordsMatched'];
    $this->nextRecord = (int)$this->records->csw_SearchResults['nextRecord'];
  }
  
  function current(): SimpleXMLElement {
    //echo "current()<br>\n";
    //echo "fisrtPos=$this->fisrtPos\n";
    //echo "currentPos=$this->currentPos\n";
    return $this->records->csw_SearchResults->csw_BriefRecord[$this->currentPos - $this->fisrtPos];
  }
  
  function key(): int {
    //echo "key()<br>\n";
    return $this->currentPos;
  }
  
  function next(): void {
    //echo "next()<br>\n";
    $this->currentPos++;
    if (($this->currentPos - $this->fisrtPos) >= 10) {
      $this->fisrtPos = $this->nextRecord;
      if (!$this->fisrtPos)
        throw new Exceprion("Erreur startPos==0");
      $records = $this->server->getRecords('dc', 'brief', $this->fisrtPos);
      $records = str_replace(['csw:','dc:'],['csw_','dc_'], $records);
      $this->records = new SimpleXMLElement($records);
      $this->numberOfRecordsMatched = (int)$this->records->csw_SearchResults['numberOfRecordsMatched'];
      $this->nextRecord = (int)$this->records->csw_SearchResults['nextRecord'];
    }
  }
  
  function valid(): bool {
    //echo "valid()<br>\n";
    return ($this->currentPos <= $this->numberOfRecordsMatched);
  }
};

/** Gestion d'un cache */
readonly class Cache {
  public string $cachedir; // '' si pas de cache
  
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
  
  function id(string $url): string {
    if (!$this->cachedir)
      return '';
    else
      return $this->cachedir.'/'.md5($url).'.xml';
  }
  
  function get(string $url): string {
    if (!$this->cachedir)
      return file_get_contents($url);
    $cachepath = $this->cachedir.'/'.md5($url).'.xml';
    if (is_file($cachepath))
      return file_get_contents($cachepath);
    $contents = file_get_contents($url);
    if ($contents === false)
      throw new Exception("Erreur '".($http_response_header[0] ?? 'unknown')."' sur url=$url");
    file_put_contents($cachepath, $contents);
    return $contents;
  }
};

readonly class CswServer {
  /** liste des serveurs connus */
  const SERVERS = [
    'sextant'=> [
      'title'=> "Sextant (Ifremer)",
      'url'=> 'https://sextant.ifremer.fr/geonetwork/srv/fre/csw',
      //'post'=> true, // l'interrogation en POST fonctionne
    ],
    'geo2france'=> [
      'title'=> "Géo2France",
      'url'=> "https://www.geo2france.fr/geonetwork/srv/fre/csw",
    ],
    'geobretagne'=> [
      'title'=> 'geobretagne',
      'url'=> 'http://geobretagne.fr/geonetwork/srv/fre/csw',
    ],
    'datara'=> [
      'title'=> "datara",
      'url'=> 'https://www.datara.gouv.fr/geonetwork/srv/eng/csw-RAIN',
    ],
    'sigloire'=> [
      'title'=> "sigloire",
      'url'=> 'https://catalogue.sigloire.fr/geonetwork/srv/fr/csw-sigloire',
    ],
    'sigena'=> [
      'title'=> "sigena",
      'url'=> 'https://www.sigena.fr/geonetwork/srv/fre/csw',
    ],
    'odd-corse'=> [
      'title'=> "Observatoire du Développement Durable de Corse (DREAL Corse)",
      'url'=> 'https://georchestra.ac-corse.fr/geonetwork/srv/fre/csw',
    ],
  ];
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
  
  public string $serverId;
  public string $title;
  public bool $post;
  public Cache $cache;
  
  function __construct(string $serverId, string $cachedir) {
    if (!isset(self::SERVERS[$serverId]))
      throw new Exception("Erreur, serveur $serverId inxeistant");
    $this->serverId = $serverId;
    $this->cache = new Cache($cachedir);
    $this->title = self::SERVERS[$serverId]['title'];
    $this->post = self::SERVERS[$serverId]['post'] ?? false;
  }
  
  function getCapabilitiesUrl(): string {
    return self::SERVERS[$this->serverId]['url'].'?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetCapabilities';
  }
  
  function getRecordsUrl(string $type, string $ElementSetName, int $startPosition, array $filter=[]): string {
    $OutputSchema = urlencode(self::GETRECORDS_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::GETRECORDS_PARAMS[$type]['namespace']);
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];
    if ($filter)
      $filter = urlencode(arrayToXml($filter));

    return self::SERVERS[$this->serverId]['url']
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecords&ElementSetName=$ElementSetName"
      .'&ResultType=results&MaxRecords=10&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&startPosition=$startPosition"
      .($filter ? "&CONSTRAINTLANGUAGE=FILTER&CONSTRAINT_LANGUAGE_VERSION=1.1.0&FILTER=$filter" : '');
  }
  
  function getRecords(string $type, string $ElementSetName, int $startPosition, array $filter=[]): string {
    //echo "CswServer::getRecords(startPosition=$startPosition)<br>\n";
    $url = $this->getRecordsUrl($type, $ElementSetName, $startPosition, $filter);
    return $this->cache->get($url);
  }
  
  function getRecordsInPost(string $type, string $ElementSetName, int $startPosition, array $filter=[]): string {
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
    //echo '<pre>'; print_r(Http::request(self::SERVERS[$this->serverId]['url'], $options)); die();
    $result = Http::request(self::SERVERS[$this->serverId]['url'], $options);
    $body = $result['body'];
    $result['body'] = str_replace('<','&lt;', $result['body']);
    echo "<pre>"; print_r($result); echo "</pre>\n";
    return $body;
  }
  
  function getMds(int $startPosition): MDs {
    return new MDs($this, $startPosition);
  }
  
  function getRecordByIdUrl(string $type, string $ElementSetName, string $id): string {
    $OutputSchema = urlencode(self::GETRECORDS_PARAMS[$type]['OutputSchema']);
    $namespace = urlencode(self::GETRECORDS_PARAMS[$type]['namespace']);
    $TypeNames = self::GETRECORDS_PARAMS[$type]['TypeNames'];
    return self::SERVERS[$this->serverId]['url']
      ."?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById&ElementSetName=$ElementSetName"
      .'&ResultType=results&OutputFormat=application/xml'
      ."&OutputSchema=$OutputSchema&NAMESPACE=$namespace&TypeNames=$TypeNames"
      ."&id=".$id;
  }
  
  function getRecordById(string $type, string $ElementSetName, string $id): string {
    $url = $this->getRecordByIdUrl($type, $ElementSetName, $id);
    return $this->cache->get($url);
  }
};


const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>gndcat</title></head><body>\n";
const NBRE_MAX_LIGNES = 38;

if (php_sapi_name() == 'cli') { // utilisation en CLI
  echo "argc=$argc\n";
  switch ($argc) {
    case 1: {
      echo "usage: php $argv[0] {catalog} {action}\n";
      echo "Liste des serveurs:\n";
      foreach (CswServer::SERVERS as $id => $server)
        echo " - $id : $server[title]\n";
      die();
    }
    case 2: {
      $id = $argv[1];
      echo "Liste des actions:\n";
      echo " - getRecords - lit les enregistrements en brief DC\n";
      echo " - list - affiche titre et type\n";
      die();
    }
    case 3: {
      $id = $argv[1];
      $action = $argv[2];
      $server = new CswServer($id, $id);
      switch ($action) {
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
          }
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
        case 'list': {
          foreach ($server->getMds() as $no => $md) {
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
    foreach (CswServer::SERVERS as $id => $server) {
      echo "<li><a href='?server=$id'>$server[title]</a></li>\n";
    }
    die();
  }

  $id = $_GET['server'];
  $server = new CswServer($id, $id);
  switch ($_GET['action'] ?? null) { // en fonction de l'action
    case null: { // menu
      echo HTML_HEADER,"<h2>Choix d'une action pour \"$server->title\"</h2><ul>\n";
      echo "<li><a href='",$server->getCapabilitiesUrl(),"'>GetCapabilities</a></li>\n";
      echo "<li><a href='?server=$id&action=list'>list</a></li>\n";
      die();
    }
    case 'list2': { // liste les métadonnées n'utilisant pas MDs
      $startPosition = $_GET['startPosition'] ?? 1;
      $url = $server->getRecordsUrl('dc', 'brief', $startPosition);
      echo "<a href='$url'>GetRecords@dc</a></p>\n";
      if ($server->post) {
        $filter = [
          'Filter'=> [
            'PropertyIsEqualTo'=> [
              'PropertyName'=> 'dc:type',
              'Literal'=> 'dataset',
            ],
          ],
        ];
        $results = $server->getRecordsInPost('dc', 'brief', $startPosition, $filter);
      }
      else {
        $results = $server->getRecords('dc', 'brief', $startPosition);
      }
      echo '<pre>',str_replace('<','&lt;',$results),"</pre>\n";
      $results = str_replace(['csw:','dc:'],['csw_','dc_'], $results);
      $results = new SimpleXMLElement($results);
      if (!$results->csw_SearchResults)
        die("Résultat erroné");
      echo "<table border=1>\n";
      echo "<tr><td>numberOfRecordsMatched</td><td>",$results->csw_SearchResults['numberOfRecordsMatched'],"</td></tr>\n";
      echo "<tr><td>startPosition</td><td>$startPosition</td></tr>\n";
      $nextRecord = $results->csw_SearchResults['nextRecord'];
      echo "<tr><td>nextRecord</td><td><a href='?server=$id&action=list&startPosition=$nextRecord'>$nextRecord</a></td></tr>\n";
      echo "</table></p>\n";
    
      echo "<table border=1>\n";
      foreach ($results->csw_SearchResults->csw_BriefRecord as $record) {
        $url = $server->getRecordByIdUrl('dcat', 'full', $record->dc_identifier);
        echo "<tr><td><a href='$url'>$record->dc_title</a> ($record->dc_type)</td><td>$url</td></tr>\n";
      }
      echo "</table>\n";
      die();
    }
    case 'list': {  // liste les métadonnées utilisant MDs
      $startPosition = $_GET['startPosition'] ?? 1;
      $nbreLignes = 0;
      echo "<table border=1>\n";
      $mds = $server->getMds($startPosition);
      foreach ($mds as $no => $record) {
        if (in_array($record->dc_type, ['FeatureCatalogue','service'])) continue;
        if (++$nbreLignes > NBRE_MAX_LIGNES) break;
        $url = $server->getRecordByIdUrl('dcat', 'full', $record->dc_identifier);
        echo "<tr><td><a href='$url'>$record->dc_title</a> ($record->dc_type)</td></tr>\n";
      }
      echo "</table>\n";
      //echo "numberOfRecordsMatched=",$mds->numberOfRecordsMatched(),"<br>\n";
      //echo "no=$no<br>\n";
      //echo "nbre=$nbre<br>\n";
      if ($nbreLignes > NBRE_MAX_LIGNES)
        echo "<a href='?server=$id&action=list&startPosition=$no'>suivant ($no / ",$mds->numberOfRecordsMatched(),")</a><br>\n";
      die();
    }
  }
}
