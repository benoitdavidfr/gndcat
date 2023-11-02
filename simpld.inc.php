<?php
/** Gestion simple d'un graphe JSON-LD ou Yaml-LD et modification des ressources du graphe.
 * La classe SimpLD gère de manière simplifiée un graphe JSON-LD ou Yaml-LD et empaquette les méthodes de JsonLD.
 * Les classes PObject, Literal, Reference et Resource permettent de réaliser des modifications sur les ressources RDF du graphe.
 */
namespace simpLD;
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use ML\JsonLD\JsonLD;

/** Liste ordonnée de propriétés par classe RDF utilisée pour Resource::sortProperties(). */
class PropOrder {
  /** @var array<string,list<string>> $classes liste ordonnée de propriétés par classe */
  readonly public array $classes;
  
  /** Initialise un objet à partir soit du contenu d'un fichier proporder soit d'un chemin vers un tel fichier.
   * @param string|array<string,list<string>> $order */
  function __construct(string|array $order) {
    if (is_string($order)) {
      if (!is_file($order))
        throw new \Exception("Erreur, fichier '$order' n'existe pas");
      $order = Yaml::parseFile($order);
    }
    $this->classes = $order['classes'];
  }
};

/** Objet d'une propriété dans un graphe aplani, cad soit un littéral, soit une référence à une ressource.
 * Dans un graphe non aplani, l'objet d'une propriété peut aussi être une ressource.
 * Si ONLY_FLATTEN_GRAPH est vrai alors la création à partir d'un graphe non aplani génère une erreur.
 */
abstract class PObject {
  /** La constante ONLY_FLATTEN_GRAPH permet d'interdire la construction d'un graphe non aplani */
  const ONLY_FLATTEN_GRAPH = false;
  /** codage du champ '@id' */
  static string $idLabel = '$@id';
  /** codage du champ '@type' */
  static string $typeLabel = '@type';

  /** Création soit d'une valeur (comme string), soit d'une référence (comme array)
   * @param string|array<string,mixed> $object */
  static function create(string|int|float|bool|array $object): self|Resource {
    //echo 'Dans PObject::create(), $object='; var_dump($object);
    // un littéral représenté en JSON par un string|int|float|bool
    if (is_string($object) || is_int($object) || is_float($object) || is_bool($object))
      return new Literal($object);
    // un littéral typé
    elseif ((count($object)==2) && array_key_exists(self::$typeLabel, $object) && array_key_exists('@value', $object))
      return new Literal($object['@value'], $object[self::$typeLabel]);
    elseif ((count($object)==1) && array_key_exists(self::$idLabel, $object))
      return new Reference($object[self::$idLabel]);
    elseif (!self::ONLY_FLATTEN_GRAPH) // @phpstan-ignore-line
      return new Resource($object);
    else
      throw new \Exception('Graphe non aplani');
  }
  
  abstract function updateCounter(): void;
  /** @return string|array<mixed> */
  abstract function asArray(int $depth=0): string|int|float|bool|array;
  function sortProperties(PropOrder $propOrder): self { return $this; }
};

/** Un littéral */
class Literal extends PObject {
  /** type éventuel du littéral */
  readonly ?string $type;
  /** valeur du littéral */
  readonly string|int|float|bool $value;

  function __construct(string|int|float|bool $value, string $type=null) { $this->value = $value; $this->type = $type; }

  function updateCounter(): void {}
  
  function asArray(int $depth=0): string|int|float|bool|array {
    if (!$this->type)
      return $this->value;
    else
      return [self::$typeLabel => $this->type, '@value'=> $this->value];
  }
};

/** Une référence à une ressource existante ou non dans le graphe */
class Reference extends PObject {
  readonly string $id;
  
  function __construct(string $id) { $this->id = $id; }

  function updateCounter(): void {
    if (isset(Resource::$graph[$this->id]))
      Resource::$graph[$this->id]->incrCounter();
  }

  /** @return array<mixed> */
  function asArray(int $depth=0): array { return [self::$idLabel => $this->id]; }
};

/** Ressource RDF et stockage d'un graphe dans la variable statique $graph */
class Resource {
  const DEPTH_MAX = 100; // profondeur max d'imbrication pour éviter les boucles
  readonly public string $id;
  /** Dict [propName -> list(PObject|Resource)].
   * Dans un graphe aplani la valeur est une liste de PObject.
   * Dans un graphe imbriqué la valeur peut aussi être une Resource.
   * @var array<string, list<PObject>> $propObjs */
  readonly public array $propObjs;
  protected int $counter=0; // compteur du nbre de références vers cette ressource
  
  /** dictionnaire des resources indexé sur leur URI et leur id pour les noeuds blancs [{uri} -> Resource]
   * @var array<string,self> */
  static array $graph=[];
  
  /** chargement d'un graphe dans self::$graph.
   * @param array<string,mixed> $graph ; le graphe en entrée
   */
  static function load(array $graph): void {
    // déduit du contexte d'éventuels labels pour @id et @type et modifie en conséquence les var. statiques correspondantes
    PObject::$idLabel = '@id';
    PObject::$typeLabel = '@type';
    foreach ($graph['@context'] ?? [] as $key => $value) {
      if ($value == '@id')
        PObject::$idLabel = $key;
      elseif ($value == '@type')
        PObject::$typeLabel = $key;
    }
    
    if (!isset($graph['@graph']))
      throw new \Exception("Erreur champ '@graph' absent");
    foreach ($graph['@graph'] as $resource) {
      self::$graph[$resource['$id']] = new self($resource);
    }
    foreach (Resource::$graph as $id => $resource) {
      $resource->updateCounter();
    }
  }
  
  /** création d'une ressource soit à partir de sa version array, soit à partir de son id et de ses propriétés.
   * @param string|array<string,mixed> $resourceOrId
   * @param array<string,mixed> $propObjs
   */
  function __construct(string|array $resourceOrId, array $propObjs=[]) {
    if (is_array($resourceOrId)) { // 1er cas de création à partir de la version array
      $resource = $resourceOrId;
      //echo 'dans Resource::__construct(), resource='; print_r($resource);
      $this->id = $resource['$id'];
      unset($resource['$id']);
      $propObjs = [];
      foreach ($resource as $prop => $pvals) {
        $propObjs[$prop] = [];
        if (is_array($pvals) && array_is_list($pvals)) {
          foreach ($pvals as $pval)
            $propObjs[$prop][] = PObject::create($pval);
        }
        else
          $propObjs[$prop] = [PObject::create($pvals)];
      }
      $this->propObjs = $propObjs;
    }
    else { // 2ème cas création à partir de son id et de ses propriétés
      $this->id = $resourceOrId;
      $this->propObjs = $propObjs;
    }
  }
  
  function getCounter(): int { return $this->counter; }
  function incrCounter(): void { $this->counter++; }
  
  /** parcours les objets des prédicats pour incrémenter les compteurs */
  function updateCounter(): void {
    foreach ($this->propObjs as $objects) {
      foreach ($objects as $object) {
        $object->updateCounter();
      }
    }
  }
  
  /** Tri l'ordre des propriétés en fonction de l'ordre défini par $propOrder */
  function sortProperties(PropOrder $propOrder): self {
    $propObjs = []; // le nouveau dict. des propriétés
    //echo "this->propObjs="; print_r($this->propObjs);
    $propList = []; // la liste des propriétés triées extraite de $propOrder
    foreach ($this->propObjs['isA'] ?? [] as $literal) {
      if (get_class($literal) <> __NAMESPACE__.'\\Literal')
        throw new \Exception("Erreur, isA non littéral");
      $className = $literal->value;
      if ($propList = $propOrder->classes[$className] ?? [])
        break;
    }
    //echo "className=$className, proplist="; print_r($propList);
    // je commence à recopier les propriétés définies dans $propOrder
    foreach ($propList as $propName) {
      //echo "propName=$propName, subProps="; print_r($subProps); echo "\n";
      if (isset($this->propObjs[$propName]))
        $propObjs[$propName] = $this->propObjs[$propName];
    }
    // puis je copie les propriétés qui ne sont pas définies dans $propOrder en conservant leur ordre initial
    foreach ($this->propObjs as $propName => $pObjs) {
      if (!array_key_exists($propName, $propObjs))
        $propObjs[$propName] = $pObjs;
    }
    // Appel récursif sur les ressources imbriquées
    foreach ($propObjs as $propName => &$pObjs) {
      foreach ($pObjs as &$pObj)
        $pObj = $pObj->sortProperties($propOrder);
    }
    return new self($this->id, $propObjs);
  }
  
  /** Retransforme la ressource en array pur.
   * les identfiants de noeuds blancs des ressources imbriquées sont supprimés.
   * @return array<string,mixed>
   */
  function asArray(int $depth=0): array {
    $propObjs = array_map(
        function(array $objs) use($depth): array|string|int|float|bool {
          if (count($objs) == 1)
            return $objs[0]->asArray($depth+1);
          else
            return array_map(
              function(PObject|Resource $po) use($depth): array|string|int|float|bool {
                return $po->asArray($depth+1);
              },
              $objs
            );
        },
        $this->propObjs
    );
    // pour les ressources imbriquées, les identfiant de noeuds blancs sont supprimés
    if ($depth && (substr($this->id, 0, 2)=='_:'))
      return $propObjs;
    else
      return array_merge(['$id'=> $this->id], $propObjs);
  }
};

/** Gestion d'un graphe LD en JSON-LD ou Yaml-LD empaquetant les méthodes de ML\JsonLD\JsonLD */
class SimpLD {
  /** @var array<mixed> $graph  stockage du graphe comme array */
  readonly public array $graph;
  
  /** initialise un objet soit à partir d'une représentation array JSON soit à partir d'un objet \EasyRdf\Graph
   * @param array<mixed>|\EasyRdf\Graph $rdfOrArray
   */
  function __construct(array|\EasyRdf\Graph $rdfOrArray) {
    if (is_array($rdfOrArray))
      $this->graph = $rdfOrArray;
    else
      $this->graph = json_decode($rdfOrArray->serialise('jsonld'), true);
    // die ("<pre>$this</pre>\n"); // affichage du JSON-LD
  }
  
  /** tri les propriétés de chaque ressource selon l'ordre défini dans $order.
   * L'ordre es défini par un dictionnaire [({pName}=> {subOrder}) | ({no}=> {pName})]
   * Si une propriété a pour objet une classe de ressources alors l'entrée doit être {pName}=> {subOrder}
   * où {subOrder} est l'ordre défini sur les sous-propriétés.
   * Si une propriété a pour objet un littéral ou une référence alors l'entrée doit être {no}=> {pName}
   * où {no} est une numéro d'ordre.
   * @param array<string,mixed> $graph ; le graphe en entrée
   * @param PropOrder $order ; l'ordre des propriétés
   * @return array<string,mixed> le graphe en retour
   */
  function sortProperties(PropOrder $order): array {
    //echo 'Graph::sortProperties(), $graph='; print_r($graph);
    // chargement du graphe dans Resource::$graph
    Resource::load($this->graph);
    
    $graph['@graph'] = [];
    foreach (Resource::$graph as $resource) {
      $graph['@graph'][] = $resource->sortProperties($order)->asArray();
    }
    return $graph;
  }

  /** Test de sortProperties() */
  static function testSortProperties(): void {
    echo '<pre>';
    if (1) { // @phpstan-ignore-line
      $graph = Yaml::parseFile('fichetest.yaml');
      $order = Yaml::parseFile('proporder.yaml');
    }
    elseif (0) { // @phpstan-ignore-line
      $graph = [
        '@graph'=> [
          [ '$id' => '_:xxx',
            'isA'=> "IMT",
            'value'=> 'application/xml',
            'label'=> "XML",
          ],
        ],
      ];
      $order = [
        'label'=> null,
        'value'=> null,
      ];
    }
    elseif (1) {
      $graph = [
        '@graph'=> [
          [
            '$id'=> '_:yyy',
            'isA'=> 'Dataset',
            'fmt'=> [ '$id' => '_:xxx',
              'isA'=> "IMT",
              'value'=> 'application/xml',
              'label'=> "XML",
            ],
          ],
        ],
      ];
      $order = [
        'label'=> null,
        'value'=> null,
      ];
    }
    echo Yaml::dump(Graph::sortProperties($graph, new PropOrder($order)), 10, 2);
  }
  
  /** Fabrique une représentation Yaml en remplacant évt. le contexte par un URI et en ord. évt. les prop. des ressources */
  function asYaml(string $contextURI='', ?PropOrder $order=null): string {
    if ($order)
      $sGraph = $this->sortProperties($order);
    else
      $sGraph = $this->graph;
    if (count($sGraph['@graph'] ?? []) == 1) {
      $graph = [];
      if ($contextURI)
        $graph['@context'] = $contextURI;
      foreach ($sGraph['@graph'][0] as $p => $o)
        $graph[$p] = $o;
    }
    else {
      $graph = $sGraph;
      if (isset($graph['@context']) && $contextURI)
        $graph['@context'] = $contextURI;
    }
    return YamlDump($graph, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
  }
  
  /** Compacte le graphe, cad lui applique le contexte.
   * @param array<string,mixed> $context ; le contexte à appliquer
   */
  function compact(array $context): self {
    $compacted = JsonLD::compact(json_encode($this->graph), json_encode($context));
    $compacted = json_decode(json_encode($compacted), true);
    return new self($compacted);
  }
  
  /** Imbrique le graphe en fonction du cadre fourni
   * @param array<string,mixed> $frame ; le cadre à utiliser
   */
  function frame(array $frame): self {
    $framed = JsonLD::frame(
      json_encode($this->graph),
      json_encode($frame));
    $framed = json_decode(json_encode($framed), true);
    //$framed['@context'] = 'https://geoapi.fr/gndcat/context.yaml';
    return new self($framed);
  }
};


if (basename(__FILE__) <> basename($_SERVER['SCRIPT_NAME'])) return; // TEST unitaire


SimpLD::testSortProperties(); // Test unitaire de Graph::sortProperties()
