<?php
namespace rdf;
/** Modification du contenu d'un graphe RDF.
 * Les classes définies dans ce fichier permettent de réaliser des modifications sur les ressources RDF du graphe.
 * La classe Graph expose les méthodes sur un graphe.
 * Le graphe étant stocké comme variable statique de la classes Resource, un seul graphe peut être défini
 * à un instant donné.
 * Le code fait l'hypothèse que les champs '@id' et '@type' sont resp. codés en JSON comme PObject::ID et PObject::TYPE.
 */
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/** Objet d'une propriété dans un graphe aplani, cad soit un littéral, soit une référence à une ressource.
 * Dans un graphe non aplani, l'objet d'une propriété peut aussi être une ressource.
 * Si ONLY_FLATTEN_GRAPH est vrai alors la création à partir d'un graphe non aplani génère une erreur.
 * Le code fait l'hypothèse que les champs '@id' et '@type' sont resp. codés en JSON comme ID et TYPE.'
 */
abstract class PObject {
  /** La constante ONLY_FLATTEN_GRAPH permet d'interdire la construction d'un graphe non aplani */
  const ONLY_FLATTEN_GRAPH = false;
  /** codage du champ '@id' */
  const ID = '$id';
  /** codage du champ '@type' */
  const TYPE = 'isA';

  /** Création soit d'une valeur (comme string), soit d'une référence (comme array)
   * @param string|array<string,mixed> $object */
  static function create(string|int|float|bool|array $object): self|Resource {
    //echo 'Dans PObject::create(), $object='; var_dump($object);
    // un littéral représenté en JSON par un string|int|float|bool
    if (is_string($object) || is_int($object) || is_float($object) || is_bool($object))
      return new Literal($object);
    // un littéral typé
    elseif ((count($object)==2) && array_key_exists(self::TYPE, $object) && array_key_exists('@value', $object))
      return new Literal($object['@value'], $object[self::TYPE]);
    elseif ((count($object)==1) && array_key_exists(self::ID, $object))
      return new Reference($object[self::ID]);
    elseif (!self::ONLY_FLATTEN_GRAPH) // @phpstan-ignore-line
      return new Resource($object);
    else
      throw new \Exception('Graphe non aplani');
  }
  
  abstract function updateCounter(): void;
  abstract function frame(int $depth): self|Resource;
  /** @return string|array<mixed> */
  abstract function asArray(): string|int|float|bool|array;
  function sortProperties(PropOrder $propOrder): self { return $this; }
};

/** Un littéral */
class Literal extends PObject {
  readonly ?string $type;
  readonly string|int|float|bool $value;

  function __construct(string|int|float|bool $value, string $type=null) { $this->value = $value; $this->type = $type; }

  function updateCounter(): void {}

  function frame(int $depth): Literal { return $this; }
  
  function asArray(): string|int|float|bool|array {
    if (!$this->type)
      return $this->value;
    else
      return [self::TYPE => $this->type, '@value'=> $this->value];
  }
};

/** Une référence à une ressource existante ou non dans le graphe */
class Reference extends PObject {
  readonly string $id;
  
  /** @param array<mixed> $object */
  function __construct(string $id) { $this->id = $id; }

  function updateCounter(): void {
    if (isset(Resource::$graph[$this->id]))
      Resource::$graph[$this->id]->incrCounter();
  }

  /** imbrication */
  function frame(int $depth): Reference|Resource {
    if (isset(Resource::$graph[$this->id]))
      return Resource::$graph[$this->id]->frame($depth+1);
    else
      return $this;
  }

  /** @return array<mixed> */
  function asArray(): array { return [self::ID => $this->id]; }
};

/** Liste ordonnée de propriétés par classe utilisée pour Resource::sortProperties(). */
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
  
  /** Fabrique un nouvel objet en remplacant chaque référence par la ressource référencée */
  function frame(int $depth=0): self {
    if ($depth >= self::DEPTH_MAX)
      throw new \Exception("depth > DEPTH_MAX");
    $propObjs = [];
    //print_r($this->propObjs);
    foreach ($this->propObjs as $prop => $objs) {
      $propObjs[$prop] = [];
      foreach ($objs as $obj) {
        $propObjs[$prop][] = $obj->frame($depth);
      }
    }
    //print_r($propObjs);
    return new self($this->id, $propObjs);
  }
  
  /** Tri l'ordre des propriétés en fonction de l'ordre défini par $propOrder */
  function sortProperties(PropOrder $propOrder): self {
    $propObjs = []; // le nouveau dict. des propriétés
    //echo "this->propObjs="; print_r($this->propObjs);
    $propList = []; // la liste des propriétés triées extraite de $propOrder
    foreach ($this->propObjs['isA'] ?? [] as $literal) {
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

/** Classe exposant des méthodes sur un graphe */
class Graph {
  /** chargement du graphe dans Resource::$graph.
   * @param array<string,mixed> $graph ; le graphe en entrée
   */
  private static function load(array $graph): void {
    if (!isset($graph['@graph']))
      throw new \Exception("Erreur champ '@graph' absent");
    foreach ($graph['@graph'] as $resource) {
      Resource::$graph[$resource['$id']] = new Resource($resource);
    }
    foreach (Resource::$graph as $id => $resource) {
      $resource->updateCounter();
    }
  }
  
  /** Fonctionnalité abandonnée au profit de JsonLD::frame() */
  private static function ABANDONED() {
    /** Imbrique un graphe aplani défini comme array ayant 2 propriétés @context et @graph.
     * SOLUTION ABANDONNEE AU PROFIF DE JsonLD::frame()
     * Dans le résultat ne sont conservées à la racine que les ressources qui ne sont pas référencées dans le graphe.
     * Si le graphe ne contient qu'une seule ressource alors elle est retournée sans la propriété @graph
     * @param array<string,mixed> $graph ; le graphe aplani en entrée
     * @return array<string,mixed> le graphe imbriqué en retour
     *
    static function frame(array $graph): array {
      // chargement du graphe dans Resource::$graph
      self::load($graph);
    
      // imbrication des resources en ne conservant que les ressources qui ne sont pas référencées dans le graphe
      // et transformation de ces ressources imbriqués en array
      $graph['@graph'] = [];
      foreach (Resource::$graph as $resource) {
        if ($resource->getCounter() == 0) {
          $graph['@graph'][] = $resource->frame()->asArray();
        }
      }
    
      // Retour du graphe en ne conservant le champ @graph que s'il existe plus d'une ressource
      if (count($graph['@graph']) <> 1)
        return $graph;
      $resource = ['@context'=> $graph['@context']];
      foreach ($graph['@graph'][0] as $p => $objs)
        $resource[$p] = $objs;
      return $resource;
    }

    / ** Test de frame() * /
    static function testFrame(): void {
      switch($_GET['action'] ?? null) {
        case null: { // menu
          echo "<a href='?action=boucle'>boucle</a><br>\n";
          echo "<a href='?action=boucleEtRacine'>boucleEtRacine, génère une exception sur DEPTH_MAX</a><br>\n";
          echo "<a href='?action=racineEtFils'>racineEtFils</a><br>\n";
          echo "<a href='?action=flatten2'>flatten2</a><br>\n";
          die();
        }
        case 'boucle': { // boucle retourne un graphe vide
          $graph = [
            '@context'=> '',
            '@graph'=> [
              [
                '$id'=> 'boucle',
                'prop'=> [
                  '$id'=> 'boucle',
                ],
              ],
            ],
          ];
          break;
        }
        case 'boucleEtRacine': { // boucleEtRacine, génère une exception
          $graph = [
            '@context'=> '',
            '@graph'=> [
              [
                '$id'=> 'racine',
                'ref'=> ['$id'=> 'boucle'],
              ],
              [
                '$id'=> 'boucle',
                'ref'=> ['$id'=> 'boucle'],
              ],
            ],
          ];
          break;
        }
        case 'racineEtFils': { // Test sur graphe ok
          $graph = [
            '@context'=> '',
            '@graph'=> [
              [
                '$id'=> 'racine',
                'ref'=> ['$id'=> 'fils'],
              ],
              [
                '$id'=> 'fils',
                'val'=> "valeur",
              ],
            ],
          ];
          break;
        }
        case 'flatten2': { // double imbrication
          $graph = [
            '@context'=> '',
            '@graph'=> [
              [
                '$id'=> 'racine',
                'ref'=> ['$id'=> 'fils'],
              ],
              [
                '$id'=> 'fils',
                'val'=> "valeur",
              ],
            ],
          ];
          $framed = Graph::frame($graph);
          echo '<pre>frameGraph = '; print_r($framed);
          $graph = ['@context'=> '', '@graph'=> [$framed]];
          break;
        }
        default: die("action $_GET[action] inconnue\n");
      }
      echo '<pre>frameGraph = '; print_r(Graph::frame($graph));
    }*/
  }
  
  /** tri les propriétés de chaque ressource selon l'ordre défini dans $order.
   * L'ordre es défini par un dictionnaire [({pName}=> {subOrder}) | ({no}=> {pName})]
   * Si une propriété a pour objet une classe de ressources alors l'entrée doit être {pName}=> {subOrder}
   * où {subOrder} est l'ordre défini sur les sous-propriétés.
   * Si une propriété a pour objet un littéral ou une référence alors l'entrée doit être {no}=> {pName}
   * où {no} est une numéro d'ordre.
   * @param array<string,mixed> $graph ; le graphe en entrée
   * @param array<mixed> $order ; l'ordre des propriétés
   * @return array<string,mixed> le graphe en retour
   */
  static function sortProperties(array $graph, PropOrder $order): array {
    //echo 'Graph::sortProperties(), $graph='; print_r($graph);
    // chargement du graphe dans Resource::$graph
    self::load($graph);
    
    $graph['@graph'] = [];
    foreach (Resource::$graph as $resource) {
      $graph['@graph'][] = $resource->sortProperties($order)->asArray();
    }
    return $graph;
  }

  /** Test de sortProperties() */
  static function testSortProperties(): void {
    echo '<pre>';
    if (1) {
      $graph = Yaml::parseFile('fichetest.yaml');
      $order = Yaml::parseFile('proporder.yaml');
    }
    elseif (0) {
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
};


if (basename(__FILE__) <> basename($_SERVER['SCRIPT_NAME'])) return; // TEST unitaire


//Graph::testFrame(); // Test unitaire de Graph::frame()

Graph::testSortProperties(); // Test unitaire de Graph::sortProperties()
