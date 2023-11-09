<?php
// csw.php - pseudo serveur CSW permettant de réutiliser le cache existant
// url de la forme csw.php?server={server}&....
require_once __DIR__.'/mdserver.inc.php';

if (!isset($_GET['server'])) {
  die("Erreur, paramètre server non défini");
}

if (!Server::exists($_GET['server'])) {
  die("Erreur, serveur $_GET[server] non défini");
}

$server = new CswServer($_GET['server'], $_GET['server']);
$result = $server->get($_GET);

header('Content-Type: text/xml');
die($result);
