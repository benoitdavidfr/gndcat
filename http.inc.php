<?php
/** gestion de requêtes Http.
 *
 * Code simplifié par rapport à httpreqst.inc.php
 *
 * journal:
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

/** gestion des requêtes Http avec les options, version 2.
 *
 * Objectif par rapport à la V1 de simplifier l'appel en (i) retournant uniquement un string et (ii) en gardant en statique
 * la dernière erreur qui peut ainsi être analysée.
 * Pour cela et pour simplifier, l'option ignore_errors est toujours active.
 */
class Http {
  /** headers retournés par le dernier appel
   * @var list<string> $lastHeaders */
  static array $lastHeaders=[];
  static string $lastErrorBody; // body du dernier appel correspondant à une erreur

  /** @return list<string> */
  static function lastHeaders(): array { return self::$lastHeaders; }
  static function lastErrorBody(): string { return self::$lastErrorBody; }
  
  /** construit le contexte http pour l'appel à file_get_contents().
   *
   * Les options sont celles définies pour call() ; renvoie un context
   * Il y a 2 types d'options:
   *   - les options de contexte, Voir [Options de contexte HTTP](https://www.php.net/manual/fr/context.http.php)
   *   - les en-têtes HTTP qui sont codées différemment
   *
   * @param array <string,string|int|number> $options; liste des options définies dans call()
   * @return mixed
   */
  static private function buildHttpContext(array $options) {
    if (!$options)
      return null;
    $header = '';
    foreach (['referer','Content-Type','Accept','Accept-Language','Cookie','Authorization'] as $key) {
      if (isset($options[$key]))
        $header .= "$key: ".$options[$key]."\r\n";
    }
    $httpOptions = $header ? ['header'=> $header] : [];
    foreach (['method','proxy','timeout','ignore_errors','content'] as $key) {
      if (isset($options[$key]))
        $httpOptions[$key] = $options[$key];
    }
    //print_r($httpOptions);
    return stream_context_create(['http'=> $httpOptions]);
  }
  
  /** Exécute un appel HTTP et retourne false en cas d'erreur ou sinon le body de la réponse.
   *
   * Dans tous les cas les headers de retour sont enregistrés dans la variable statique $lastHeaders,
   * qui peut ainsi être consultée après le retour de l'appel.
   * En cas d'erreur, le body est enregistré dans la variable statique $lastErrorBody,
   * ce qui permet de ne pas perdre ce body en cas d'erreur.'
   *
   * L'option 'ignore_errors' est systématiquement forcée dans un souci de simplicité.
   *
   * Le timeout est géré de manière particulière car Php ne retourne alors pas de headers.
   * Pour que ce soit transparent pour l'utilisateur, dans ce cas une erreur 'HTTP/1.1 504 Time-out' est génèrée
   * qui peut être analysée comme les autres erreurs.
   *
   * Les options possibles sont:
   *   'max-retries' => nbre de relances à afire en cas de timeout, 0 <=> un seul appel, défaut 0
   *   'method'=> méthode HTTP à utiliser, par défaut 'GET'
   *   'referer'=> referer à utiliser, par défaut aucun
   *   'timeout'=> Délai maximal d'attente pour la lecture, sous la forme d'un nombre décimal (e.g. 10.5)
   *   'Accept'=> liste des types MIME demandés, ex 'application/json,application/geo+json'
   *   'Accept-Language'=> langage demandé, ex 'en'
   *   'Authorization' => en-tête HTTP Authorization permettant l'authentification d'un utilisateur
   *   'Cookie' => cookies définis
   *   'proxy'=> proxy à utiliser
   *   'content'=> texte à envoyer en POST ou PUT
   *   'Content-Type'=> Content-Type à utiliser pour les méthodes POST et PUT
   *
   * @param array<string,string|int|number> $options les options de l'appel, voir ci-dessus les valeurs possibles.
   */
  static function call(string $url, array $options=[]): string|false {
    //echo "Http::call($url)\n";
    $nbretries = $options['max-retries'] ?? 0; // nombre de relances à effectuer en cas d'erreur de timeout
    //echo "nbretries=$nbretries<br>\n";
    unset($options['max-retries']); // l'option 'max-retries' est retirée car ce n'est pas une option de buildHttpContext()
    $options['ignore_errors'] = true; // l'option ignore_errors est forcée
    $sleep_duration = 1; // durée d'attente avant de renvoyer un nouvel appel, multiplié par 2 à chaque itération
    while (true) {
      // si pas d'erreur alors on sort de la boucle pour retourner le résultat
      if (($body = @file_get_contents($url, false, self::buildHttpContext($options))) !== false) {
        //echo "sortie de boucle sur !erreur<br>\n";
        break;
      }
        
      // si $http_response_header est non vide <=> erreur <> timeout => on sort de la boucle pour retourner le résultat
      if (isset($http_response_header) && (count($http_response_header) != 0)) { // @phpstan-ignore-line
        //echo "sortie de boucle sur http_response_header est non vide<br>\n";
        break;
      }
      
      if ($nbretries-- < 0) { // le nombre max d'appel est passé => on sort de la boucle
        //echo "sortie de boucle sur nbretries < 0<br>\n";
        break;
      }
      
      // sinon, il s'agit d'un timeout et je boucle 'max-retries' + 1 fois en doublant la durée à chaque itération
      echo "time-out sur $url, nbretries=$nbretries, attente $sleep_duration s<br>\n";
      sleep($sleep_duration);
      $sleep_duration = 2 * $sleep_duration;
    }
    
    if (count($http_response_header) == 0) { // erreur de timeout
      self::$lastHeaders = [
        'HTTP/1.1 504 Time-out', 
        'Content-Type: text/plain; charset=UTF-8',
      ];
      self::$lastErrorBody = 'Timeout';
      return false;
    }
    
    self::$lastHeaders = $http_response_header;
    if (self::errorCode() == 200) // pas d'erreur
      return $body;
    
    // erreur <> timeout
    self::$lastErrorBody = $body;
    return false;
  }
  
  /** analyse les dernières en-têtes et retourne le code d'erreur HTTP ou -2 si ce code n'est pas trouvé.
   *
   * Dans le cas où l'option ignore_errors est définie à true, un code d'erreur HTTP est retourné dans les headers.
   * Cette méthode les analyse pour y trouver ce code d'erreur.
   * Le code d'erreur est normalement dans la première ligne des headers.
   * Cependant, en cas de redirection, le code est dans la suite des headers
   * Voir https://www.php.net/manual/fr/context.http.php
   */
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
  
  /** extrait des derniers headers le Content-Type */
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
    echo "<li><a href='?action=timeOutCall'>Appel timeout -> retourne le code d'erreur timeout</a></li>\n";
    echo "</ul>\n";
    echo "<li><b>Appels avec l'option max-retries=3</b></li><ul>\n";
    echo "<li><a href='?action=timeOutCallMR3'>Appel timeout -> retourne le code d'erreur timeout</a></li>\n";
    echo "<li><a href='?action=timeOutCallMR31/2'>Appel timeout 1/2 -> retourne Ok ou le code d'erreur timeout</a></li>\n";
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
    $result = Http::call($url, ['Accept-Language'=> 'fr', 'ignore_errors'=> true]);
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
    $result = Http::call($url, ['timeout'=> 5]);
    break;
  }
  case 'timeOutCallMR3': {
    $url = selfUrl().'?action=timeOut';
    echo "url=$url<br>\n";
    $result = Http::call($url, ['max-retries'=> 3, 'timeout'=> 5]);
    break;
  }
  case 'timeOutCallMR31/2': {
    $url = selfUrl().'?action=timeOut1/2';
    echo "url=$url<br>\n";
    $result = Http::call($url, ['max-retries'=> 3, 'timeout'=> 5]);
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
  echo "<tr><td>lastErrorBody</td><td><pre>";
  print_r(Http::lastErrorBody());
}
echo "</pre></td></tr>",
     "<tr><td>errorCode</td><td><pre>",Http::errorCode(),"</td></tr>",
     "<tr><td>contentType</td><td><pre>",Http::contentType(),"</td></tr>";
if (Http::contentType() == 'application/json')
  echo "<tr><td>JSON</td><td><pre>",Yaml::dump(json_decode($result, true)),"</pre></td></tr>\n";
echo "</table>\n";
