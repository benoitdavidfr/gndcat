<?php
/** Vérification dans un document Yaml des contraintes d'intégrité définies dans son schema.
 * Les contraintes d'intégrité sont définies dans le schéma sous la forme d'objets referentialIntegrity
 * associés à une propriété et contenant 2 propriétés:
 *  - label: une étiquette décrivant la contrainte
 *  - path: un chemin définissant le domaine de validité
 */
//namespace jsonschema;

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>checkIntegrity</title></head><body>\n";

if (!isset($_GET['fpath'])) {
  echo HTML_HEADER;
  echo "<a href='?fpath=registre'>registre</a><br>\n";
  echo "<a href='?fpath=reg'>reg</a><br>\n";
  die();
}

$docpath = "$_GET[fpath].yaml"; // le chemin du doc à vérifier
$schemapath = "$_GET[fpath].schema.yaml"; // le chemin du schéma contenant les règles d'intégrité

abstract class Struct { // Literal | Object | Array | DefRef | OneOf | ...
  readonly public ?string $description;
  /** 
   * @param array<mixed> $srce; le scre
   */
  function __construct(array $srce) {
    $this->description = $srce['description'] ?? null;
  }
  
  /** Crée un Struct
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  static function create(array $srce, array $path): self {
    //echo "Struct::create(srce, path=",implode('/',$path),")<br>\n";
    switch ($srce['type'] ?? null) {
      case null: {
        if (isset($srce['$ref']))
          return new DefRef($srce, $path);
        elseif (isset($srce['oneOf']))
          return new OneOf($srce, $path);
        else
          die("No type et !oneOf pour path ".implode('/', $path));
      }
      case 'object': return new JSObject($srce, $path);
      case 'array': return new JSArray($srce, $path);
      case 'string': return new Literal($srce, $path);
      case 'null': return new Literal($srce, $path);
      default: die("type $srce[type] non traité dans Struct::create() pour path ".implode('/', $path));
    }
  }
  
  /** liste des références à des définitions
   * @param array<string> $path
   * @return array<string,DefRef>
   */
  abstract function defRefs(array $path): array;
  
  /** Teste si la données est conforme au schéma du point de vue de la structure.
   * Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<string,string> */
  abstract function checkStruct(array|string|null $data, array $path): array;

  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<mixed>
   */
  abstract function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array;
};

class RefIntegrity {
  readonly public ?string $label;
  readonly public string $path;
  
  /**
   * @param array<string,string> $refIntegrity
   * @param list<string> $path le chemin de l'objet créé
  */
  function __construct(array $refIntegrity, array $path) {
    echo "Création d'une RefIntegrity pour path=",implode('/',$path),"<br>\n";
    $this->label = $refIntegrity['label'];
    $this->path = $refIntegrity['path'];
  }

  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    echo "RefIntegrity::checkIntegrity(data=",json_encode($refdata),", path=",implode('/',$path),")<br>\n";
    $vals = $baseData->path($this->path);
    return [implode('/',$path)
        => in_array($refdata, $vals) ? 'ok pour '.json_encode($refdata)
           : json_encode($refdata).' !in_array '.json_encode($vals)];
  }
};

/** élément littéral */
class Literal extends Struct {
  readonly public string $type;
  readonly public ?RefIntegrity $refIntegrity;

  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path;
   */
  function __construct(array $srce, array $path) {
    $this->type = $srce['type'];
    $refIntegrity = $srce['referentialIntegrity'] ?? null;
    $this->refIntegrity = isset($refIntegrity['path']) ? new RefIntegrity($refIntegrity, $path) : null;
  }

  /** liste des références à des définitions
   * @param array<string> $path
   * @return array<string,DefRef>
   */
  function defRefs(array $path): array { return []; }
  
  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<string,string> */
  function checkStruct(array|string|null $data, array $path): array {
    //echo "Literal::check(schema, data, path=",implode('/',$path),")<br>\n";
    //echo '<pre>this='; print_r($this); echo "</pre>\n";
    switch ($this->type) {
      case 'string': {
        if (is_string($data)) {
          //echo "string ok<br>\n";
          return [];
        }
        else {
          //echo "string KO<br>\n";
          return [implode('/',$path) => '!'.$this->type];
        }
      }
      case 'null': {
        if (is_null($data)) {
          //echo "null ok<br>\n";
          return [];
        }
        else {
          //echo "null KO<br>\n";
          return [implode('/',$path) => '!'.$this->type];
        }
      }
      default: die("Type $this->type non traité");
    }
  }
  
  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    echo "Literal::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    if ($this->refIntegrity) {
      echo "Vérification<br>\n";
      return $this->refIntegrity->checkIntegrity($refdata, $path, $baseData);
    }
    else
      return [];
  }
};

class Property {
  readonly public Struct $main;
  readonly public ?string $description;
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    //echo "Property::_construct(srce, path=",implode('/',$path),")<br>\n";
    $this->main = Struct::create($srce, $path);
    $this->description = $srce['description'] ?? null;
  }
  
  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<string,string> */
  function checkStruct(array|string|null $data, array $path): array {
    //echo "Property::check(schema, data, path=",implode('/',$path),")<br>\n";
    return $this->main->checkStruct($data, $path);
  }
  
  /** 
   * @param array<mixed>|string|null $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    echo "Appel de Property::checkIntegrity(refdata, path=",implode('/',$path),")<br>\n";
    return $this->main->checkIntegrity($refdata, $path, $baseData);
  }
};

/** Objet défini dans le schéma */
class JSObject extends Struct {
  /** @var array<string,Property> $properties */
  readonly public array $properties;
  /** @var array<string,Property> $patternProperties */
  readonly public array $patternProperties;
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    $properties = [];
    $patternProperties = [];
    if (isset($srce['properties'])) {
      foreach ($srce['properties'] as $name => $property) {
        $properties[$name] = new Property($property, array_merge($path,[$name]));
      }
    }
    elseif (isset($srce['patternProperties'])) {
      foreach ($srce['patternProperties'] as $pattern => $property) {
        $patternProperties[$pattern] = new Property($property, array_merge($path,[$pattern]));
      }
    }
    elseif (0) { // @phpstan-ignore-line // je peux avoir des object sans properties, faire une alerte à la place
      throw new Exception("Object sans properties et sans patternProperties pour path ".implode('/', $path));
    }
    $this->properties = $properties;
    $this->patternProperties = $patternProperties;
  }
  
  /** liste des références à des définitions
   * @param array<string> $path
   * @return array<string,DefRef>
   */
  function defRefs(array $path): array {
    //return array_map(function(JSProperty $p) { return $p->main->defRefs(); }, array_values($this->properties));
    $defRefs = [];
    foreach ($this->properties as $name => $p) {
      $defRefs = array_merge($defRefs, $p->main->defRefs(array_merge($path, [$name])));
    }
    return $defRefs;
  }

  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<mixed> */
  function checkStruct(array|string|null $data, array $path): array {
    //echo "JSObject::check(schema, data, path=",implode('/',$path),")<br>\n";
    if (!is_array($data)) return [implode('/',$path)=> "!object"];
    $result = [];
    foreach ($data as $key => $value) {
      if (isset($this->properties[$key])) {
        $check = $this->properties[$key]->checkStruct($value, array_merge($path, [$key]));
        if ($check)
          $result[implode('/', array_merge($path, [$key]))] = $check;
        elseif (0) // @phpstan-ignore-line
          $result[implode('/', array_merge($path, [$key]))] = 'ok';
      }
      else {
        foreach ($this->patternProperties as $pattern => $property) {
          if (preg_match("!$pattern!", $key)) {
            $check = $property->checkStruct($value, array_merge($path, [$key]));
            if ($check)
              $result[implode('/', array_merge($path, [$key]))] = $check;
            elseif (0) // @phpstan-ignore-line
              $result[implode('/', array_merge($path, [$key]))] = 'ok';
          }
        }
      }
    }
    return $result;
  }
  
  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    $result = [];
    foreach ($this->properties as $key => $prop) {
      if (isset($refdata[$key]))
        if ($i = $prop->checkIntegrity($refdata[$key], array_merge($path, [$key]), $baseData))
          $result = array_merge($result, $i);
    }
    foreach ($this->patternProperties as $pattern => $prop) {
      foreach ($refdata as $key => $value) {
        if (preg_match("!$pattern!", $key)) {
          if ($i = $prop->checkIntegrity($value, array_merge($path, [$key]), $baseData))
            $result = array_merge($result, $i);
        }
      }
    }
    return $result;
  }
};

class JSArray extends Struct {
  readonly public Struct $items;

  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    echo "JSArray::_construct(srce, path=",implode('/',$path),")<br>\n";
    //echo '<pre>srce='; print_r($srce); echo "</pre>\n";
    $this->items = Struct::create($srce['items'], $path);
  }

  /** liste des références à des définitions
   * @param array<string> $path
   * @return array<string,DefRef>
   */
  function defRefs(array $path): array { return []; }
  
  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<mixed> */
  function checkStruct(array|string|null $data, array $path): array {
    //echo "JSArray::check(schema, data, path=",implode('/',$path),")<br>\n";
    if (!is_array($data) || !array_is_list($data)) return [implode('/',$path)=> "!list"];
    $result = [];
    foreach ($data as $i => $value) {
      $key = array_merge($path, [$i]);
      if ($check = $this->items->checkStruct($value, $key))
        $result[implode('/',$key)] = $check;
    }
    return $result;
  }
  
  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    echo "JSArray::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    $result = [];
    foreach ($refdata as $i => $item) {
      if ($int = $this->items->checkIntegrity($item, array_merge($path, [$i]), $baseData))
        $result = array_merge($result, $int);
    }
    return $result;
  }
};

class OneOf extends Struct {
  /** @var list<Struct> $oneOf */
  readonly public array $oneOf;
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    $oneOf = [];
    foreach ($srce['oneOf'] as $struct)
      $oneOf[] = Struct::create($struct, $path);
    $this->oneOf = $oneOf;
  }

  /** liste des références à des définitions
   * @param array<string> $path
   * @return array<string,DefRef>
   */
  function defRefs(array $path): array { return []; }
  
  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<string,string> */
  function checkStruct(array|string|null $data, array $path): array {
    //echo "OneOf::check(schema, data, path=",implode('/',$path),")<br>\n";
    foreach ($this->oneOf as $i => $one) {
      if ($one->checkStruct($data, array_merge($path, ["oneOf:$i"])) == [])
        return [];
    }
    return [implode('/',$path)=> 'None valid'];
  }
  
  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    echo "OneOf::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    foreach ($this->oneOf as $i => $one) {
      if ($one->checkStruct($refdata, array_merge($path, ["oneOf:$i"])) == [])
        return $one->checkIntegrity($refdata, $path, $baseData);
    }
    return [implode('/',$path)=> 'None valid'];
  }
};

class Definition {
  readonly public Struct $content;
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    $this->content = Struct::create($srce, $path);
  }
};

class DefRef extends Struct {
  readonly public string $ref;
  
  /** 
   * @param array<mixed> $srce
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    parent::__construct($srce);
    $this->ref = $srce['$ref'];
  }

  /** liste des références à des définitions.
   * @param array<string> $path
   * @return array<string,DefRef>
   */
  function defRefs(array $path): array { return [implode('/',$path) => $this]; }

  function definition(): ?Struct {
    $name = substr($this->ref, strlen('#/definitions/'));
    $def = Schema::$schema->definitions[$name] ?? null;
    return $def ? $def->content : null;
  }
  
  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<string,string> */
  function checkStruct(array|string|null $data, array $path): array {
    //echo "DefRef::check(schema, data, path=",implode('/',$path),")<br>\n";
    if ($def = $this->definition())
      return $def->checkStruct($data, $path);
    elseif (!in_array($this->ref, ['http://json-schema.org/schema#'])) {
      echo "Attention: \$ref $this->ref non défini<br>\n";
    }
    return [];
  }
  
  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    echo "DefRef::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    //echo '<pre>data='; print_r($data); echo "\nthis="; print_r($this); echo '</pre>';
    $name = substr($this->ref, strlen('#/definitions/'));
    if ($this->definition())
      //return $this->definition()->checkIntegrity($data, array_merge($path, ["definition:$name"]));
      return $this->definition()->checkIntegrity($refdata, $path, $baseData);
    else
      return [];
  }
};

class Schema {
  /** @var array<Definition> $definitions */
  readonly public array $definitions;
  readonly public Struct $main; // définition de l'objet principal du schéma
  static public self $schema;
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path=[]) {
    $definitions = [];
    foreach ($srce['definitions'] ?? [] as $name => $def) {
      $definitions[$name] = new Definition($def, array_merge($path, ['definitions', $name]));
    }
    $this->definitions = $definitions;
    
    $this->main = Struct::create($srce, $path);
    
    $this->checkDefinitions();
    self::$schema = $this;
  }
  
  function checkDefinitions(): void {
    //echo '<pre>$defRefs='; print_r($this->main->defRefs());
    foreach ($this->main->defRefs([]) as $path => $defRef) {
      //echo '<pre>$defRef='; print_r($defRef);
      //echo $defRef->ref,"<br>\n";
      $name = substr($defRef->ref, strlen('#/definitions/'));
      if (!isset($this->definitions[$name]))
        echo "Erreur: Définition '$name' référencée dans $path et non définie<br>\n";
    }
  }
  
  /** Teste si la données est conforme au schéma du point de vue de la structure.
   * Si oui retourne [], sinon retourne [{path}=> {erreur}]
   * @param array<mixed> $data
   * @return array<string,string> */
  function checkStruct(array $data): array {
    return $this->main->checkStruct($data, []);
  }
  
  /**
   * @param array<mixed> $data
   * @return array<mixed>
   */
  function checkIntegrity(array $data): array {
    echo "Schema::checkIntegrity(data)<br>\n";
    return $this->main->checkIntegrity($data, [], new BaseData($data));
  }
};

$schema = new Schema(Yaml::parseFile($schemapath));
//echo '<pre>$schema='; print_r($schema);

$status = $schema->checkStruct(Yaml::parseFile($docpath));
if ($status) {
  echo '<pre>status='; print_r($status);
  die();
}

$checkIntegrity = $schema->checkIntegrity(Yaml::parseFile($docpath));
echo '<pre>checkIntegrity='; print_r($checkIntegrity);
die();
