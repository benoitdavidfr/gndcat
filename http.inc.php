<?php
/** gestion de requêtes Http.
 *
 * Code simplifié et amélioré par rapport à httpreqst.inc.php.
 * Renvoie un string|false comme file_get_contents() et non plus un array avec body et headers compliqué à traiter.
 *
 * journal:
 * - 5/11/2023
 *   - passage en version 2.1 incompatible avec la version 2.0
 *   - les headers doivent être distinguées des options et sont définis dans le champ header des options
 *   - ajout d'une erreur réseau plus généale que l'erreur time-out.
 *   - changement des codes d'erreur pour les erreurs réseau et time-out
 * - 28/10/2023
 *   - ajout possibilité de demander de lancer une exception en cas d'erreur
 * - 20/10/2023
 *   - ajout tests unitaires en mode web
 *   - ajout traitement du timeout, si appel avec ignore_errors, requelt() ne lance jamais d'exception
 *     mais renvoit un code erreur timeout
 *   - ajout d'une option max-retries
 *   - call() renvoit string|false et plus array
 * - 1/11/2021:
 *   - prise en compte de l'option timeout
 * - 15/2/2021:
 *   - création
 */

/** gestion des requêtes Http avec les options, version 2.1. (5/11/2023)
 *
 * Objectif de la V2 par rapport à la V1 de simplifier l'appel en (i) retournant uniquement un string et (ii) en gardant
 * en statique la dernière erreur qui peut ainsi être analysée après le retour du call.
 * Pour permettre cela et pour simplifier le code, l'option ignore_errors est toujours activée.
 * La version 2.1 modifie:
 *  i) la gestion des headers. En 2.0 les options et les headers étaient mélangées ce qui présentait l'inconvénient
 *     d'avoir à les séparer dans buildHttpContext() et aussi d'avoir à modifier le code pour ajouter des headers
 *     ce qui est parfois nécessaire.
 *     En 2.1 les headers sont définis dans le champ header des options. Les 2 versions sont donc en partie incompatibles.
 *  ii) la gestion des erreurs ne définissant pas $http_response_header.
 *     La 2.1 distingue les erreurs de time-out des erreurs de réseau et pour ces dernières n'effectue pas de relance.
 *  iii) à chaque relance le time-out est doublé.
 */
class Http {
  const NETWORK_ERROR = 600;
  const TIME_OUT_ERROR = 601;

  /** headers retournés par le dernier appel
   * @var list<string> $lastHeaders */
  static array $lastHeaders=[];
  static string $lastErrorMessage; // body du dernier appel correspondant à une erreur

  /** @return list<string> */
  static function lastHeaders(): array { return self::$lastHeaders; }
  static function lastErrorMessage(): string { return self::$lastErrorMessage; }
  
  /** construit le contexte http pour l'appel à file_get_contents().
   *
   *   - les options de contexte, Voir [Options de contexte HTTP](https://www.php.net/manual/fr/context.http.php)
   *   - les en-têtes HTTP qui sont codées différemment
   *
   * @param array <string,string|int|number|array<string,string>> $options; liste des options définies dans call()
   * @return mixed
   */
  static private function buildHttpContext(array $options) {
    $options['ignore_errors'] = true; // l'option ignore_errors est forcée
    if (isset($options['header'])) {
      $kv = fn(string $k, string $v): string => "$k: $v";
      $headers = array_map($kv, array_keys($options['header']), array_values($options['header']));
      $options['header'] = implode("\r\n", $headers);
    }
    //print_r($options);
    return stream_context_create(['http'=> $options]);
  }
  
  /** Effectue un appel HTTP et retourne le body de la réponse ou false en cas d'erreur.
   *
   * Dans tous les cas les headers de retour sont enregistrés dans la variable statique $lastHeaders,
   * et peuvent ainsi être consultés après le retour de l'appel.
   * En cas d'erreur:
   *  - le body est enregistré dans la variable statique $lastErrorMessage.
   *  - si l'option throw-on-error est définie alors lance une exception avec comme message le body retourné
   *    et comme code le code Http retourné, sinon retourne false.
   *
   * L'option 'ignore_errors' est systématiquement forcée afin de pouvoir disposer des headers et du body en cas d'erreur.
   *
   * En cas d'erreur réseau ou time-out, Php ne retournant pas de headers, pour faciliter l'usage de la classe,
   * une erreur particulière est génèrée qui peut être traitée comme les autres erreurs avec 2 codes HTTP supplémentaires
   * pour ces 2 erreurs et qui sont définis en constante de classe.
   *
   * Les options possibles (voir https://www.php.net/manual/fr/context.http.php pour plus de détails) sont: 
   *   'max-retries' => nbre de relances à faire en cas de timeout, 0 <=> un seul appel, défaut 0
   *   'throw-on-error' => si défini et true alors lance une exception en cas d'erreur, défaut pas défini
   *     Ces 2 premières options ne sont pas des options de stream_context_create()
   *   'method'=> méthode HTTP à utiliser, par défaut 'GET'
   *   'referer'=> referer à utiliser, par défaut aucun
   *   'timeout'=> Délai maximal d'attente pour la lecture, sous la forme d'un nombre décimal (e.g. 10.5)
   *   'proxy'=> proxy à utiliser, par défaut aucun
   *   'content'=> contenu à envoyer en POST ou PUT
   *   'header' => dictionnaire clé/valeur des en-têtes à envoyer dans l'appel
   *     Cette option est transformée dans self::buildHttpContext()
   *
   * Les headers les plus fréquents (voir https://developer.mozilla.org/fr/docs/Web/HTTP/Headers) sont:
   *   'Accept'=> liste des types MIME acceptés par le client séparés par une ',', ex 'application/json,application/geo+json'
   *   'Accept-Language'=> langue acceptée par le client, ex 'en'
   *   'Authorization' => en-tête d'authentification de l'utilisateur,
   *     exemple: 'Basic ' suivi du {login}:{mdp} encodé avec base64_encode()
   *   'Cookie' => cookies définis, chacun sous la forme "{key}={value}", séparés par '; '
   *   'Content-Type'=> type MIME du contenu envoyé avec les méthodes POST et PUT, ex: 'application/json'
   *
   * @param array<string,string|int|number|array<string,string>> $options les options de l'appel,
   *    voir ci-dessus les valeurs possibles.
   */
  static function call(string $url, array $options=[]): string|false {
    //echo "Http::call($url)\n";
    $nbretries = $options['max-retries'] ?? 0; // nombre de relances à effectuer en cas d'erreur réseau
    unset($options['max-retries']); // l'option 'max-retries' est retirée car ce n'est pas une option de buildHttpContext()
    $throwOnError = $options['throw-on-error'] ?? false;
    unset($options['throw-on-error']);
    $sleep_duration = 1; // durée d'attente avant de renvoyer un nouvel appel, multiplié par 2 à chaque itération
    while (true) {
      $timeOut = $options['timeout'] ?? ini_get('max_execution_time'); // La durée effective du time-out
      $startTime = time();
      $exec_duration = 0;
      // si pas d'erreur alors on sort de la boucle pour retourner le résultat
      if (($body = @file_get_contents($url, false, self::buildHttpContext($options))) !== false) {
        //echo "sortie de boucle sur !erreur<br>\n";
        break;
      }
      $exec_duration = time() - $startTime; // durée de l'appel pour distinguer un time-out d'une erreur réseau
        
      // si $http_response_header est non vide <=> erreur <> network|time-out => on sort de la boucle pour retourner cette erreur
      if (isset($http_response_header) && (count($http_response_header) != 0)) { // @phpstan-ignore-line
        //echo "sortie de boucle sur http_response_header est non vide<br>\n";
        break;
      }
      
      if ($exec_duration < $timeOut) { // Erreur réseau différente de time-out => pas de relance
        break;
      }
      
      // sinon, il s'agit d'une erreur de time-out
      // je boucle 'max-retries' + 1 fois en doublant à chaque itération la durée d'attente et le time-out
      if (--$nbretries < 0) { // le nombre max d'appel est passé => on sort de la boucle
        //echo "sortie de boucle sur nbretries < 0<br>\n";
        break;
      }
      
      echo "erreur time-out sur $url, nbretries=$nbretries, timeout=$timeOut s, attente=$sleep_duration s<br>\n";
      sleep($sleep_duration);
      $sleep_duration = 2 * $sleep_duration;
      $timeOut = 2 * $timeOut;
      $options['timeout'] = $timeOut;
    }
    
    if (!isset($http_response_header) || (count($http_response_header) == 0)) { // @phpstan-ignore-line // erreur de réseau|time-out
      if ($exec_duration < $timeOut) {
        return self::error($throwOnError, self::NETWORK_ERROR, 'Network Error');
      }
      else {
        return self::error($throwOnError, self::TIME_OUT_ERROR, 'Time-out Error');
      }
    }
    
    self::$lastHeaders = $http_response_header;
    $errorCode = self::errorCode();
    if ($errorCode >= 300) // erreur <> network ou time-out
      return self::error($throwOnError, $errorCode, $body);
    
    // pas d'erreur
    return $body;
  }
  
  /** Gère le retour en cas d'erreur */
  static private function error(bool $throwOnError, int $errorCode, string $errorMessage): false {
    if (in_array($errorCode, [self::NETWORK_ERROR, self::TIME_OUT_ERROR])) {
      self::$lastHeaders = [
        sprintf('HTTP/1.1 %d %s', $errorCode, $errorMessage),
        'Content-Type: text/plain; charset=UTF-8',
      ];
    }
    self::$lastErrorMessage = $errorMessage;
   
    if (!$throwOnError)
      return false;
    throw new Exception($errorMessage, $errorCode);
  }
  
  /** analyse les dernières en-têtes et retourne le code d'erreur HTTP ou -2 si ce code n'est pas trouvé. */
  static function errorCode(): int {
    //print_r($headers[0]); echo "\n";
    $errorCode = (int)substr(self::$lastHeaders[0], 9, 3); // code d'erreur http dans la première ligne des headers
    if (!in_array($errorCode, [301, 302]))
      return $errorCode;
    
    // en cas de redirection, if faut rechercher le code d'erreur dans la suite du header
    for ($i = 1; $i < count(self::$lastHeaders); $i++) {
      if (preg_match('!HTTP/1\.. (\d+)!', self::$lastHeaders[$i], $matches) && !in_array($matches[1], [301,302])) {
        return (int)$matches[1];
      }
    }
    echo "Erreur, code d'erreur Http non trouvé dans Http::errorCode()<br>\n";
    return -2;
  }
  
  /** extrait des derniers headers le Content-Type ou retourne '' s'il n'est pas trouvé */
  static function contentType(): string {
    foreach (self::$lastHeaders as $header) {
      if (substr($header, 0, 14) == 'Content-Type: ')
        return substr($header, 14);
    }
    return '';
  }
};


if ((php_sapi_name()=='cli') || (basename(__FILE__) <> basename($_SERVER['PHP_SELF']))) return;
/**** Test unitaire en web *****/


/** Serveur test pour tester la classe Http.
 *
 * Ce script est aussi utilisé pour appeler Http.
 */
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

function selfUrl(): string { // Url d'appel sans les paramètres GET
  $url = ($_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http')
        ."://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]"
        .($_SERVER['PATH_INFO'] ?? '');
  //echo "selfUrl=$url\n";
  return $url;
}

const HTML_HEADER = "<!DOCTYPE HTML>\n<html><head><title>httptest</title></head><body>\n";

switch ($_GET['action'] ?? null) {
  case null: { // appel pour fournir le menu de tests
    echo HTML_HEADER,"<b>Menu de tests utitaires de la classe HttpV2</b><ul>\n";
    echo "<li><b>Appels sans l'option max-retries</b></li><ul>\n";
    echo "<li><a href='?action=simpleCall'>Appel simple -> retourne la page d'accueil et le header avec code 200</a></li>\n";
    echo "<li><a href='?action=serverCall'>Appel SERVER avec Accept-Language -> retourne SERVER en JSON</a></li>\n";
    echo "<li><a href='?action=404Call'>Appel erreur 404 -> retourne le code d'erreur</a></li>\n";
    echo "<li><a href='?action=timeOutCall'>Appel timeout -> retourne le code d'erreur time-out</a></li>\n";
    echo "<li><a href='?action=nonExistentServer'>Appel nonExistentServer -> retourne le code d'erreur réseau</a></li>\n";
    echo "</ul>\n";
    echo "<li><b>Appels avec l'option max-retries=3</b></li><ul>\n";
    echo "<li><a href='?action=nonExistentServerMR3'>Appel nonExistentServer -> retourne le code d'erreur network</a></li>\n";
    echo "<li><a href='?action=timeOutCallMR3'>Appel timeout -> retourne le code d'erreur time-out</a></li>\n";
    echo "<li><a href='?action=timeOutCallMR31/2'>Appel timeout 1/2 -> retourne Ok ou le code d'erreur time-out</a></li>\n";
    echo "</ul>\n";
    die();
  }
  case 'simpleCall': {
    $result = Http::call(selfUrl());
    break;
  }
  case 'serverCall': {
    $url = selfUrl().'?action=SERVER';
    echo "url=$url<br>\n";
    $result = Http::call($url, ['header'=> ['Accept-Language'=> 'fr']]);
    break;
  }
  case 'nonExistentServer': {
    $url = 'xxx://nonexitent/';
    echo "url=$url<br>\n";
    $result = Http::call($url);
    break;
  }
  case '404Call': {
    $url = selfUrl().'?action=404';
    echo "url=$url<br>\n";
    $result = Http::call($url);
    break;
  }
  case 'timeOutCall': {
    $url = selfUrl().'?action=timeOut';
    echo "url=$url<br>\n";
    $result = Http::call($url, ['timeout'=> 3]);
    break;
  }
  case 'nonExistentServerMR3': {
    $url = 'xxx://nonexitent/';
    echo "url=$url<br>\n";
    $result = Http::call($url, ['max-retries'=> 3, 'timeout'=> 3]);
    break;
  }
  case 'timeOutCallMR3': {
    $url = selfUrl().'?action=timeOut';
    echo "url=$url<br>\n";
    $result = Http::call($url, ['max-retries'=> 3, 'timeout'=> 3]);
    break;
  }
  case 'timeOutCallMR31/2': {
    $url = selfUrl().'?action=timeOut1/2';
    echo "url=$url<br>\n";
    $result = Http::call($url, ['max-retries'=> 3, 'timeout'=> 3]);
    break;
  }

  case '404': {
    header("HTTP/1.1 404 Not Found");
    die("Non trouvé");
  }
  case 'timeOut': { // timeout systématique
    sleep(3*60);
    die("Fin du sleep");
  }
  case 'timeOut1/2': { // timeout 1 fois sur 2
    if (rand(1, 10) <= 5)
      die("Time out aborté");
    sleep(3*60);
    die("Fin du sleep");
  }
  case 'SERVER': {
    header('Content-type: application/json');
    echo json_encode($_SERVER);
    die();
  }
  default: die("Action $_GET[action] inconnue\n");
}

echo "<table border=1>";
if ($result!==false) {
  echo "<tr><td>result</td><td><pre>";
  print_r($result);
}
else {
  echo "<tr><td>lastErrorMessage</td><td><pre>";
  print_r(Http::lastErrorMessage());
}
echo "</pre></td></tr>",
     "<tr><td>errorCode</td><td><pre>",Http::errorCode(),"</td></tr>",
     "<tr><td>contentType</td><td><pre>",Http::contentType(),"</td></tr>";
if (Http::contentType() == 'application/json')
  echo "<tr><td>JSON</td><td><pre>",Yaml::dump(json_decode($result, true)),"</pre></td></tr>\n";
echo "</table>\n";
