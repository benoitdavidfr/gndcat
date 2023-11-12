<?php
/** Vérification dans un document Yaml des contraintes d'intégrité définies dans son schema.
 * Les contraintes d'intégrité sont définies dans le schéma sous la forme d'objets referentialIntegrity
 * associés à une propriété et contenant 2 propriétés:
 *  - label: une étiquette décrivant la contrainte
 *  - path: un chemin définissant les valeurs auxquelles la proprité doit appartenir
 *
 * Attention aux limites suivantes:
 *  - La classe Schema définit une variable statique $schema utilisée pour recherche les définitions ;
 *    elle peut créer des problèmes si plusieurs schemas sont créés.
 *    Pour éviter ces problèmes cette variable est réécrite à chaque appel d'une méthode de Schema.
 *  - les oneOf doivent se distinguer par leur structure (ex: string <> object) ou les champs obligatoires ;
 *    si différentes branches du oneOf ont la même structure alors le code peut être erroné.
 *    Pour éviter les erreurs, cette situation est testée et génère une erreur.
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/path.php';

use Symfony\Component\Yaml\Yaml;

// Permet de définir les options de verbosité
//Schema::$options['__construct'] = true;
//Schema::$options['checkStruct'] = true;
//Schema::$options['checkIntegrity'] = true;

const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>checkIntegrity</title></head><body>\n";

if (!isset($_GET['file'])) { // choix du fichier à vérifier
  echo HTML_HEADER;
  echo "<a href='?file=registre'>registre</a><br>\n";
  echo "<a href='?file=reg'>reg</a><br>\n";
  echo "<a href='?file=checkintegrity.test'>test</a><br>\n";
  die();
}

/** Union des types d'élts du schéma Literal ou Object ou Array ou DefRef ou OneOf.
 * Peut nécessiter d'être étendue. Dans ce cas le create() génèrera un die(). */
abstract class Struct { // Literal | Object | Array | DefRef | OneOf | ...
  readonly public ?string $description;
  /** 
   * @param array<mixed> $srce; le scre
   */
  function __construct(array $srce) {
    $this->description = $srce['description'] ?? null;
  }
  
  /** Crée un Struct, effectue un new d'une des sous-classes.
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  static function create(array $srce, array $path): self {
    if (Schema::$options['__construct'] ?? false)
      echo "Struct::create(srce, path=",implode('/',$path),")<br>\n";
    if (is_array($srce['type'] ?? null)) {
      $oneOf = [];
      foreach ($srce['type'] as $t) {
        $oneOf[] = ['type'=> $t];
      }
      //echo '<pre>oneOf=',Yaml::dump($oneOf); echo "</pre>\n";
      return self::create(['oneOf'=> $oneOf], array_merge($path, ['oneOf']));
    }
    return match ($srce['type'] ?? null) {
      null => isset($srce['$ref']) ?
          new DefRef($srce, $path)
            : (isset($srce['oneOf']) ?
              new OneOf($srce, $path)
                : die('No type, !oneOf et !$ref dans Struct::create() pour path '.implode('/', $path))),
      'object' => new JSObject($srce, $path),
      'array' => new JSArray($srce, $path),
      'string',
      'number',
      'integer',
      'null' => new Literal($srce, $path),
      default=> die("type ".json_encode($srce['type'])
        ." non traité dans Struct::create() pour path ".implode('/', $path)),
    };
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
  abstract function checkStruct(array|string|float|int|null $data, array $path): array;

  /** Vérifie l'intégrité, renvoie un message pour chaque valeur testée.
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<mixed>
   */
  abstract function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array;
};

/** Stoke les contraintes d'intégrité définies dans le schéma */
class RefIntegrity {
  readonly public ?string $label;
  readonly public string $path;
  
  /**
   * @param array<string,string> $refIntegrity
   * @param list<string> $path le chemin de l'objet créé
  */
  function __construct(array $refIntegrity, array $path) {
    if (Schema::$options['__construct'] ?? false)
      echo "Création d'une RefIntegrity pour path=",implode('/',$path),"<br>\n";
    $this->label = $refIntegrity['label'];
    $this->path = $refIntegrity['path'];
  }

  /** Vérifie l'intégrité, renvoie un message pour chaque valeur testée.
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    if (Schema::$options['checkIntegrity'] ?? false)
      echo "RefIntegrity::checkIntegrity(data=",json_encode($refdata),", path=",implode('/',$path),")<br>\n";
    $vals = $baseData->path($this->path);
    return [implode('/',$path)
        => in_array($refdata, $vals) ? 'ok pour '.json_encode($refdata)
           : json_encode($refdata).' !in_array '.json_encode($vals)];
  }
};

/** Stoke un élément littéral du schéma */
class Literal extends Struct {
  readonly public string $type; // type de l'élément: 'string', 'null', ...
  readonly public ?RefIntegrity $refIntegrity; // contrainte référentielle éventuelle associée

  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path;
   */
  function __construct(array $srce, array $path) {
    if (Schema::$options['__construct'] ?? false)
      echo "Literal::_construct(srce, path=",implode('/',$path),")<br>\n";
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
  function checkStruct(array|string|float|int|null $data, array $path): array {
    if (Schema::$options['checkStruct'] ?? false) {
      echo "Literal::checkStruct(data, path=",implode('/',$path),")<br>\n";
      //echo '<pre>this='; print_r($this); echo "</pre>\n";
    }
    $result = match($this->type) {
      'null' => is_null($data) ? [] : [implode('/',$path) => '!'.$this->type],
      'string' => is_string($data) ? [] : [implode('/',$path) => '!'.$this->type],
      'number' => (is_float($data) || is_int($data)) ? [] : [implode('/',$path) => '!'.$this->type],
      'integer' => is_int($data) ? [] : [implode('/',$path) => '!'.$this->type],
      default => die("Type $this->type non traité dans Literal::checkStruct()"),
    };
    if ($result && (Schema::$options['checkStruct'] ?? false)) {
      echo "<pre>Literal::check(data, path=",implode('/',$path),") -> ";
      print_r($result);
    }
    return $result;
  }
  
  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    //echo "Literal::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    if ($this->refIntegrity) {
      //echo "Vérification<br>\n";
      return $this->refIntegrity->checkIntegrity($refdata, $path, $baseData);
    }
    else
      return [];
  }
};

/** Stoke une propriété Yaml ddéfinie dans le schéma */ 
class Property {
  readonly public Struct $main; // l'objet de la propriété
  readonly public ?string $description; // une description éventuelle
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    if (Schema::$options['__construct'] ?? false)
      echo "Property::_construct(srce, path=",implode('/',$path),")<br>\n";
    $this->main = Struct::create($srce, $path);
    $this->description = $srce['description'] ?? null;
  }
  
  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<string,string> */
  function checkStruct(array|string|float|int|null $data, array $path): array {
    if (Schema::$options['checkStruct'] ?? false)
      echo "Property::checkStruct(schema, data, path=",implode('/',$path),")<br>\n";
    $check = $this->main->checkStruct($data, $path);
    if ($check && (Schema::$options['checkStruct'] ?? false)) {
      echo '<pre>Property::checkStruct='; print_r($check); echo "</pre>\n";
    }
    return $check;
  }
  
  /** 
   * @param array<mixed>|string|null $refdata
   * @param array<string> $path
   * @return array<string,string>
   */
  function checkIntegrity(array|string|float|int|null $refdata, array $path, BaseData $baseData): array {
    //echo "Appel de Property::checkIntegrity(refdata, path=",implode('/',$path),")<br>\n";
    return $this->main->checkIntegrity($refdata, $path, $baseData);
  }
};

/** Objet Yaml défini dans le schéma */
class JSObject extends Struct {
  /** @var array<string,Property> $properties */
  readonly public array $properties;
  /** @var array<string,Property> $patternProperties */
  readonly public array $patternProperties;
  /** @var list<string> $required liste des propriétés obligatoires */
  readonly public ?array $required;
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    if (Schema::$options['__construct'] ?? false)
      echo "JSObject::_construct(srce, path=",implode('/',$path),")<br>\n";
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
    $this->required = $srce['required'] ?? [];
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

  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}].
   * Le test s'effectue sur la structure ainsi que sur l'existence dans les données des champs obligatoires.
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<mixed> */
  function checkStruct(array|string|float|int|null $data, array $path): array {
    if (Schema::$options['checkStruct'] ?? false)
      echo "JSObject::check(data, path=",implode('/',$path),")<br>\n";
    if (!is_array($data) || array_is_list($data)) return [implode('/',$path)=> "!object"];
    foreach ($this->required as $pname) {
      if (!isset($data[$pname]))
        return [implode('/',$path)=> "$pname obligatoire et non défini"];
    }
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

/** Array Yaml défini dans le schéma */
class JSArray extends Struct {
  readonly public Struct $items; // structure des éléments de l'array

  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    if (Schema::$options['__construct'] ?? false)
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
  function checkStruct(array|string|float|int|null $data, array $path): array {
    if (Schema::$options['checkStruct'] ?? false)
      echo "JSArray::check(data, path=",implode('/',$path),")<br>\n";
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
    //echo "JSArray::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    $result = [];
    foreach ($refdata as $i => $item) {
      if ($int = $this->items->checkIntegrity($item, array_merge($path, [$i]), $baseData))
        $result = array_merge($result, $int);
    }
    return $result;
  }
};

/** Alternative entre défirrentes structures définies dans le schéma */
class OneOf extends Struct {
  /** @var list<Struct> $oneOf */
  readonly public array $oneOf;
  
  /** 
   * @param array<mixed> $srce; le scre
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    if (Schema::$options['__construct'] ?? false)
      echo "OneOf::__construct(baseData, path=",implode('/',$path),")<br>\n";
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
   * @return array<mixed> */
  function checkStruct(array|string|float|int|null $data, array $path): array {
    if (Schema::$options['checkStruct'] ?? false)
      echo "OneOf::check(schema, data, path=",implode('/',$path),")<br>\n";
    $errors = []; // résultats des checkStruct()
    $valids = []; // liste des braches valides
    foreach ($this->oneOf as $i => $one) {
      $errors[$i] = $one->checkStruct($data, array_merge($path, ["oneOf:$i"]));
      if ($errors[$i] == [])
        $valids[$i] = 1;
    }
    if (count($valids) == 1)
      return [];
    elseif (count($valids) == 0)
      return [implode('/',$path)=> 'None of the alternative structures is valid'];
    else
      return [implode('/',$path)=> ['Several of the alternative structures are valid' => $errors]];
  }
  
  /** Vérifie l'intégrité, renvoie un message pour chaque valeur testée.
   * Le choix de la branche suivie est effectuée en onction de la structure.
   * Ainsi si différents branches on même structure cette logique est erronée.
   * @param array<mixed> $refdata
   * @param array<string> $path
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    if (Schema::$options['checkIntegrity'] ?? false)
      echo "OneOf::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    $ones = []; // les branches du oneOf correspondant à la donnée
    foreach ($this->oneOf as $i => $one) {
      if ($one->checkStruct($refdata, array_merge($path, ["oneOf:$i"])) == [])
        $ones[] = $one;
    }
    if (count($ones) == 1)
      return $ones[0]->checkIntegrity($refdata, $path, $baseData);
    elseif (count($ones) > 1)
      return [implode('/',$path)=> 'Several valid'];
    return [implode('/',$path)=> 'None valid'];
  }
};

/** Référence à une définition définie dans le schéma */
class DefRef extends Struct {
  const PREDEF = [
    'http://json-schema.org/schema#',
  ]; // définition prédéfinies
  readonly public string $ref; // le clé de la définition avec '#/definitions/' en tête
  
  /** 
   * @param array<mixed> $srce
   * @param list<string> $path le chemin de l'objet créé
   */
  function __construct(array $srce, array $path) {
    if (Schema::$options['__construct'] ?? false)
      echo "DefRef::__construct(baseData, path=",implode('/',$path),")<br>\n";
    parent::__construct($srce);
    $this->ref = $srce['$ref'];
  }

  /** liste des références à des définitions.
   * @param array<string> $path
   * @return array<string,DefRef>
   */
  function defRefs(array $path): array { return [implode('/',$path) => $this]; }

  /** Retourne la définition référencée si elle existe dans le schéma ou null.
   * Attention ce déréférencement utilise la variable statique Schema::$schema */
  function definition(): ?Struct {
    $name = substr($this->ref, strlen('#/definitions/'));
    return Schema::$schema->definitions[$name] ?? null;
  }
  
  /** Teste si la donnée est conforme au schéma. Si oui retourne [], sinon retourne [{path}=>{erreur}]
   * @param array<mixed> $data
   * @param array<string> $path
   * @return array<string,string> */
  function checkStruct(array|string|float|int|null $data, array $path): array {
    if (Schema::$options['checkStruct'] ?? false)
      echo "DefRef::checkStruct(data, path=",implode('/',$path),")<br>\n";
    if ($def = $this->definition())
      return $def->checkStruct($data, $path);
    elseif (in_array($this->ref, self::PREDEF)) {
      return is_array($data) ? [] : ['schema prédéfini'];
    }
    echo "Attention: \$ref $this->ref non défini<br>\n";
    return [];
  }
  
  /** 
   * @param array<mixed> $refdata
   * @param array<string> $path
   */
  function checkIntegrity(array|string|null $refdata, array $path, BaseData $baseData): array {
    //echo "DefRef::checkIntegrity(data, path=",implode('/',$path),")<br>\n";
    //echo '<pre>data='; print_r($data); echo "\nthis="; print_r($this); echo '</pre>';
    $name = substr($this->ref, strlen('#/definitions/'));
    if ($this->definition())
      //return $this->definition()->checkIntegrity($data, array_merge($path, ["definition:$name"]));
      return $this->definition()->checkIntegrity($refdata, $path, $baseData);
    else
      return [];
  }
};

/** Classe des schémas JSON.
 * Attention, la définition d'une variable statique peut créer des contraintes. */
class Schema {
  /** @var array<string,Struct> $definitions */
  readonly public array $definitions;
  readonly public Struct $main; // structure de l'objet principal du schéma
  static public self $schema;
  /** @var array<string,string|bool> $options Options de verbosité [{optionName}=> {value}] */
  static public array $options=[];
  
  /** Construit le schéma à partir des données.
   * @param array<mixed> $baseData; les données issues de Yaml contenant le schéma ou les référencant
   */
  function __construct(array $baseData) {
    if (Schema::$options['__construct'] ?? false)
      echo "Schema::__construct(baseData)<br>\n";
    if (!isset($baseData['$schema']))
      die("Erreur, Pas de schéma dans les données");
    if (is_string($baseData['$schema'])) {
      $schemaPath = $baseData['$schema'].'.schema.yaml';
      if (!is_file($schemaPath))
        die("Erreur, le fichier $schemaPath n'existe pas");
      $srce = Yaml::parseFile($schemaPath);
    }
    elseif (is_array($baseData['$schema'])) {
      $srce = $baseData['$schema'];
    }
    else
      die("Erreur, \$schema ni string ni object");
    
    $definitions = [];
    foreach ($srce['definitions'] ?? [] as $name => $def) {
      $definitions[$name] = Struct::create($def, ['definitions', $name]);
    }
    $this->definitions = $definitions;
    
    $this->main = Struct::create($srce, []);
    
    $this->checkDefinitions();
    self::$schema = $this;
  }
  
  function checkDefinitions(): void {
    //echo '<pre>$defRefs='; print_r($this->main->defRefs());
    foreach ($this->main->defRefs([]) as $path => $defRef) {
      //echo '<pre>$defRef='; print_r($defRef);
      //echo $defRef->ref,"<br>\n";
      if (in_array($defRef->ref, DefRef::PREDEF)) continue;
      $name = substr($defRef->ref, strlen('#/definitions/'));
      if (!isset($this->definitions[$name]))
        echo "Erreur: Définition '$name' référencée dans $path et non définie<br>\n";
    }
  }
  
  /** Teste si la donnée est conforme au schéma du point de vue de la structure.
   * Attention la vérification est limitée à la structure.
   * Si oui retourne [], sinon retourne [{path}=> {erreur}].
   * @param array<mixed> $data
   * @return array<string,string> */
  function checkStruct(array $data): array {
    if (Schema::$options['checkStruct'] ?? false)
      echo "Schema::checkStruct(data)<br>\n";
    self::$schema = $this;
    return $this->main->checkStruct($data, []);
  }
  
  /** Vérifie l'intégrité, renvoie un message pour chaque valeur testée.
   * Ne fonctionne correctement que si les oneOf sont distinguables sur les structures, par exemple string|object
   * @param array<mixed> $data
   * @return array<mixed>
   */
  function checkIntegrity(array $data): array {
    //echo "Schema::checkIntegrity(data)<br>\n";
    self::$schema = $this;
    return $this->main->checkIntegrity($data, [], new BaseData($data));
  }
};

$schema = new Schema(Yaml::parseFile("$_GET[file].yaml"));
//echo '<pre>$schema='; print_r($schema);

// Vérification que les données correspondent au schéma
if ($status = $schema->checkStruct(Yaml::parseFile("$_GET[file].yaml"))) {
  echo "La vérification ne peut être effectuée car les données ne sont pas conformes à leur schéma ;<br>\n",
       "Les erreurs suivantes ont été détectées:<br>\n";
  echo '<pre>',Yaml::dump($status, 10, 2);
  die();
}

// Vérification des contraintes d'intégrité
$checkIntegrity = $schema->checkIntegrity(Yaml::parseFile("$_GET[file].yaml"));
echo "Résulats de l'évaluation des contraintes d'intégrité:<br>\n";
echo '<pre>',Yaml::dump(['result'=> $checkIntegrity], 10, 2);
die();
