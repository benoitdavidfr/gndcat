<?php
/** Imbrication d'un graphe RDF.
 * La méthode Resource::frameGraph() prend en entrée un graphe aplani et l'imbrique (frame).
 * Cela consiste à identifier les ressources qui ne sont pas référencées par d'autre ressource du graphe
 * et à remplacer dans ces ressources les références par les ressources référencées.
 * Les identifiants des ressources imbriquées correspondant à des noeuds blancs sont supprimés.
 * Le graphe ne doit pas comporter de boucle car s'il en comporte soit elles sont ignorées, soit elles génèreent
 * une exception.
 * Ce code est pragmatique mais probablement peu extensible et réutilisable, cependant un code plus extensible
 * et réutilisable serait plus complexe à utiliser, notamment en créer une classe représentant un graphe.
 */
require_once __DIR__.'/vendor/autoload.php';

/** Objet d'une propriété dans un graphe aplani, cad soit une valeur, soit une référence à une ressource.
 * Dans un graphe non aplani, l'objet d'une propriété peut aussi être une ressource.
 * Je fais l'hypothèse que la création est toujours efectuée à partir d'un graphe aplani.
 */
abstract class PObject {
  /** La constante ONLY_FLATTEN_GRAPH permet d'interdire la construction d'un graphe non aplani */
  const ONLY_FLATTEN_GRAPH = false;
  /** Création soit d'une valeur (comme string), soit d'une référence (comme array)
   * @param string|array<string,mixed> $object */
  static function create(string|array $object): self|Resource {
    if (is_string($object))
      return new Value($object);
    elseif (count($object)==1)
      return new Reference($object);
    elseif (!self::ONLY_FLATTEN_GRAPH) // @phpstan-ignore-line
      return new Resource($object);
    else
      throw new Exception('Graphe non aplani');
  }
  
  abstract function updateCounter(): void;
  abstract function frame(int $depth): self|Resource;
  /** @return string|array<mixed> */
  abstract function asArray(): string|array;
};

/** Valeur */
class Value extends PObject {
  readonly string $value;

  function __construct(string $value) { $this->value = $value; }

  function updateCounter(): void {}

  function frame(int $depth): Value { return $this; }
  
  function asArray(): string { return $this->value; }
};

/** Référence à une ressource existante ou non dans le graphe */
class Reference extends PObject {
  readonly string $id;
  
  /** @param array<mixed> $object */
  function __construct(array $object) { $this->id = $object['$id']; }

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
  function asArray(): array { return ['$id'=> $this->id]; }
};

/** Ressource RDF et stockage d'un graphe dans la variable statique $graph */
class Resource {
  const DEPTH_MAX = 100; // profondeur max d'imbrication pour éviter les boucles
  readonly public string $id;
  /** Dict [prop -> list(PObject|Resource)].
   * Dans un graphe aplani la valeur est une liste de PObject.
   * Dans un graphe imbriqué la valeur peut aussi être une Resource.
   * @var array<string, list<PObject>> $propObjs */
  readonly public array $propObjs;
  protected int $counter=0; // compteur du nbre de référencement à la ressource
  
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
      throw new Exception("depth > DEPTH_MAX");
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
  
  /** Retransforme la ressource en array pur.
   * @return array<string,mixed>
   */
  function asArray(): array {
    $propObjs = array_map(
        function(array $objs): array|string {
          if (count($objs) == 1)
            return $objs[0]->asArray();
          else
            return array_map(
              function(PObject|Resource $po): array|string {
                return $po->asArray();
              },
              $objs
            );
        },
        $this->propObjs
    );
    if (substr($this->id, 0, 2)=='_:')
      return $propObjs;
    else
      return array_merge(['$id'=> $this->id], $propObjs);
  }
  
  /** Imbrique un graphe aplani défini comme array avec 2 propriétés @context et @graph.
   * Dans le résultat ne sont conservés à la racine que les ressources qui ne sont pas référencées dans le graphe.
   * @param array<string,mixed> $graph ; le graphe aplani en entrée
   * @return array<string,mixed> le graphe imbriqué en retour
   */
  static function frameGraph(array $graph): array {
    // chargement du graphe dans self::$graph
    if (!isset($graph['@graph']))
      throw new Exception("Erreur champ '@graph' absent");
    foreach ($graph['@graph'] as $resource) {
      self::$graph[$resource['$id']] = new self($resource);
    }
    $graph['@graph'] = [];
    foreach (self::$graph as $id => $resource) {
      $resource->updateCounter();
    }
    
    // imbrication des resources en ne conservant que les ressources qui ne sont pas référencées dans le graphe
    // et transformation de ces ressources imbriqués en array
    foreach (self::$graph as $resource) {
      if ($resource->getCounter() == 0) {
        $graph['@graph'][] = $resource->frame()->asArray();
      }
    }
    
    // Retour du graphe en ne conservant le champ @graph que s'il existe plus d'une ressource
    if (count($graph['@graph']) <> 1)
      return $graph;
    $result = ['@context'=> $graph['@context']];
    foreach ($graph['@graph'][0] as $p => $objs)
      $result[$p] = $objs;
    return $result;
  }
};


if (basename(__FILE__) <> basename($_SERVER['SCRIPT_NAME'])) return; // TEST unitaire


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
    $framed = Resource::frameGraph($graph);
    echo '<pre>frameGraph = '; print_r($framed);
    $graph = ['@context'=> '', '@graph'=> [$framed]];
    break;
  }
  default: die("action $_GET[action] inconnue\n");
}
echo '<pre>frameGraph = '; print_r(Resource::frameGraph($graph));
