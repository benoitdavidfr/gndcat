<?php
/** Transformation d'une fiche ISO 19139 en DCAT-AP ou GeoDCAT-AP en utilisant le XSLT de GeoDCAT-AP API */

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/http.inc.php';

abstract Class GeoDcatApAbstract {
  abstract function asTurtle(): string;

  static function test(): void {
    echo "Test GeoDcatAp<br>\n";
    //$cswUrl = 'https://sextant.ifremer.fr/geonetwork/srv/fre/csw?'; // URL d'origine
    $cswUrl = 'http://php82/geoapi/gndcat/csw.php?server=sextant&'; // URL passant par csw.php
    $urlGmdFull = $cswUrl
      .'SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById'
      .'&ElementSetName=full&ResultType=results&OutputFormat=application/xml'
      .'&OutputSchema=http%3A%2F%2Fwww.isotc211.org%2F2005%2Fgmd'
      .'&NAMESPACE=xmlns%28gmd%3Dhttp%3A%2F%2Fwww.isotc211.org%2F2005%2Fgmd%29'
      .'&TypeNames=gmd:MD_Metadata&id=34c8d98c-9aea-4bd6-bdf4-9e1041bda08a';
    if (0) { // Appel de l'URL en direct
      echo "url=$urlGmdFull<br>\n";
      $result = Http::call($urlGmdFull);
      echo '<pre>',str_replace('<','&lt;',$result);
    }
    else { // Appel de l'URL par isodcatapapi
      $geoDcatAp = new (get_called_class())($urlGmdFull);
      echo '<pre>',str_replace('<','&lt;',$geoDcatAp->asTurtle());
    }
    die();
  }
};

/** Transformation par appel de l'API en local
 */
class GeoDcatApUsingApiInLocal extends GeoDcatApAbstract {
  readonly public string $urlGmdFull;
  
  function __construct(string $urlGmdFull) { $this->urlGmdFull = $urlGmdFull; }
  
  function asTurtle(): string {
    $urlGeoDCATAP = 'http://isodcatapapi/?outputSchema=core&src='.urlencode($this->urlGmdFull);
    $turtle = Http::call($urlGeoDCATAP, ['header'=>['Accept'=> 'text/turtle']]);
    print_r(Http::$lastHeaders);
    return $turtle;
  }
};

if ((php_sapi_name()<>'cli') && (basename(__FILE__) == basename($_SERVER['PHP_SELF']))) {
  // **** TEST ****
  if ('GeoDcatApUsingApiInLocal' == ($_GET['testedClass'] ?? null))
    GeoDcatApUsingApiInLocal::test();
}

/** Effectue la transformation d'une fiche XML ISO 19139 en DCAT-AP ou GeoDCAT-AP en utilisant le XSLT */
class GeoDcatApUsingXslt extends GeoDcatApAbstract {
  const OUTPUT_SCHEMAS = [
    'core'=> [
      'label'=> 'DCAT-AP',
      'xslt'=> 'https://raw.githubusercontent.com/SEMICeu/iso-19139-to-dcat-ap/master/iso-19139-to-dcat-ap.xsl',
      'params' => [
        'profile' => 'core'
      ],
    ],
    'extended'=> [
      'label'=> 'GeoDCAT-AP',
      'xslt'=> 'https://raw.githubusercontent.com/SEMICeu/iso-19139-to-dcat-ap/master/iso-19139-to-dcat-ap.xsl',
      'params' => [
        'profile' => 'extended',
      ],
    ],
  ];
  const DEFAULT_OUTPUT_SCHEMA = 'core';
  
  readonly public string $urlGmdFull;
  
  function __construct(string $urlGmdFull) { $this->urlGmdFull = $urlGmdFull; }

  /** Retourne la fiche transformée */
  function asEasyRdf(string $outputSchema=self::DEFAULT_OUTPUT_SCHEMA): \EasyRdf\Graph {
    if (!isset(self::OUTPUT_SCHEMAS[$outputSchema]))
      throw new Exception("Erreur, $outputSchema inconnu");
    
    // Loading the source document 
    $xml = new DOMDocument;
    if (!$xml->load($this->urlGmdFull)) {
      returnHttpError(404);
    }
    
    // Loading the XSLT to transform the source document into RDF/XML
    $xsl = new DOMDocument;
    if (!is_file('iso-19139-to-dcat-ap.xsl')) {
      $content = file_get_contents(self::OUTPUT_SCHEMAS[$outputSchema]['xslt']);
      file_put_contents('iso-19139-to-dcat-ap.xsl', $content);
    }
    if (!$xsl->load('iso-19139-to-dcat-ap.xsl')) {
      returnHttpError(404);
    }
    
    // Transforming the source document into RDF/XML
    $proc = new XSLTProcessor();
    $proc->importStyleSheet($xsl);

    foreach (self::OUTPUT_SCHEMAS[$outputSchema]['params'] as $k => $v) {
      $proc->setParameter("", $k, $v);
    }

    if (!$rdf = $proc->transformToXML($xml)) {
      returnHttpError(404);
    }
    
    // Setting namespace prefixes
    \EasyRdf\RdfNamespace::set('adms', 'http://www.w3.org/ns/adms#');
    \EasyRdf\RdfNamespace::set('cnt', 'http://www.w3.org/2011/content#');
    \EasyRdf\RdfNamespace::set('dc', 'http://purl.org/dc/elements/1.1/');
    \EasyRdf\RdfNamespace::set('dcat', 'http://www.w3.org/ns/dcat#');
    \EasyRdf\RdfNamespace::set('dqv', 'http://www.w3.org/ns/dqv#');
    \EasyRdf\RdfNamespace::set('geodcatap', 'http://data.europa.eu/930/');
    \EasyRdf\RdfNamespace::set('geosparql', 'http://www.opengis.net/ont/geosparql#');
    \EasyRdf\RdfNamespace::set('locn', 'http://www.w3.org/ns/locn#');
    \EasyRdf\RdfNamespace::set('prov', 'http://www.w3.org/ns/prov#');
    
    // Creating the RDF graph from the RDF/XML serialisation
    $graph = new \EasyRdf\Graph;
    $graph->parse($rdf, null, 'URI');
    
    return $graph;
  }
  
  function asTurtle(string $outputSchema=self::DEFAULT_OUTPUT_SCHEMA): string {
    $rdf = $this->asEasyRdf($outputSchema);
    $turtle = $rdf->serialise('turtle');
    return $turtle;
  }
};

if ((php_sapi_name()<>'cli') && (basename(__FILE__) == basename($_SERVER['PHP_SELF']))) {
  // **** TEST ****
  if ('GeoDcatApUsingXslt' == ($_GET['testedClass'] ?? null))
    GeoDcatApUsingXslt::test();
}

if ((php_sapi_name()=='cli') || (basename(__FILE__) <> basename($_SERVER['PHP_SELF']))) return;
// **** TEST ****

echo "<a href='?testedClass=GeoDcatApUsingApiInLocal'>Tester GeoDcatApUsingApiInLocal</a><br>\n";
echo "<a href='?testedClass=GeoDcatApUsingXslt'>Tester GeoDcatApUsingXslt</a><br>\n";
