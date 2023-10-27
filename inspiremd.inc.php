<?php
/** Structuration comme array d'une fiche de MD Inspire à partir d'une fiche ISO 19139
 * en sélectionnant les champs Inspire */
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/mdvars2.inc.php';

use Symfony\Component\Yaml\Yaml;

// fonction nécessaire dans mdvars2.inc.php pour standardiser les organisations
function stdOrganisationName(string $val): string { return $val; }

class InspireMd {
  static function val(string $val): string|float|int {
  if (ctype_digit($val))
    return intval($val);
  elseif (is_numeric($val))
    return floatval($val);
  else
    return $val;
}

  /*function val(string $val): string|float|int {
    $val2 = val2($val);
    echo "$val -> (",gettype($val2),")$val2\n";
    return $val2;
  }*/

  /** @param array<string,mixed> $subelt
   * @return array<string,mixed>|string|int */
  static function elt2Val(array $subelt): array|string|float|int {
    if (count($subelt) == 1) {
      return self::val($subelt['val']);
    }
    else {
      //print_r($subelt);
      $subrec = [];
      if (isset($subelt['svar']) && isset($subelt['val'])) {
        $subrec[$subelt['svar']] = self::val($subelt["val"]);
      }
      for ($i=0; $i<=4; $i++) {
        if (isset($subelt["svar$i"]) && isset($subelt["sval$i"])) {
          $subrec[$subelt["svar$i"]] = self::val($subelt["sval$i"]);
        }
      }
      return $subrec;
    }
  }

  /** Transforme un mdrecord en array de type JSON.
   * @param array<string,mixed> $mdrecord
   * @return array<string,mixed>
   */
  static function mdrecord2array(array $mdrecord): array {
    $record = [];
    foreach ($mdrecord as $var => $elt) {
      if (count($elt) == 1)
        $record[$var] = self::elt2Val($elt[0]);
      else {
        foreach ($elt as $i => $subelt) {
          $record[$var][$i] = self::elt2Val($subelt);
        }
      }
    }
    return $record;
  }

  /** convertit une chaine XML en un array type JSON
   * @return array<string,mixed> */
  static function convert(string $xml): array {
    $record = Mdvars::extract($xml); // @phpstan-ignore-line
    //echo '<pre>',Yaml::dump($record,4),"</pre>\n";
    return self::mdrecord2array($record);
  }
  
  /** indicateur qualité basique
   * @param array<string,mixed> $record */
  static function quality(array $record): float {
    return count($record) / count(Mdvars::$mdvars); // @phpstan-ignore-line
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


$xml = file_get_contents('ficheexample.xml');
$record = InspireMd::convert($xml);
echo '<pre>',Yaml::dump($record, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
//var_dump(mdrecord2array($record));
