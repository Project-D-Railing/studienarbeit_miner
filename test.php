<?php
include_once './settings.php';
require_once 'classes/bahnapi.php';
require_once 'classes/MysqliDb.php';
$apikeys = SETTING_APIKEYSTEST;
$apikeys2 = array_reverse($apikeys);
$minute = date("i", time());
// switching key order
if ($minute % 2 == 0) {
    $bahnapi = new bahnapi($apikeys);
} else {
    $bahnapi = new bahnapi($apikeys2);
}
$params = date("Y-m-d H:i:s", time() - 3600);
$mysqlislave = new mysqli(SETTING_DB_IP, SETTING_DB_USER, SETTING_DB_PASSWORD, SETTING_DB_NAME);
// resetting station fetching
if($minute == 0 || $minute == "00" || $minute == "0") {
    $stationsquery = $mysqlislave->query("UPDATE haltestellen2 set fetchtime='2017-12-01 00:00:00'");        
}
$stationsquery = $mysqlislave->query("SELECT EVA_NR as nr, NAME FROM haltestellen2 WHERE fetchactive2=1 AND fetchtime < '$params' ORDER BY fetchtime ASC LIMIT 0,135");
$stationen = array();
while ($row = $stationsquery->fetch_assoc()) {
    $stationen[] = array('nr' => $row['nr'],'name' => $row["NAME"]);
}
$bahnapi->addToErrorLog("Anz. Fetch: " .count($stationen));
usleep(5000000);
foreach ($stationen as $key => $station) {
    // get all trains and delays for station
    $timeold = time() - 7200;
    $zuege = $bahnapi->getTimetable($station['nr'], $timeold);
    $string = "Station: " . $station['nr'] . " fetched";
}
// write error log
$db = new MysqliDb(SETTING_DB_IP, SETTING_DB_USER, SETTING_DB_PASSWORD, SETTING_DB_NAME);
$errors = json_encode($bahnapi->getErrorLog());
$errordata = array("log" => $errors);
$db->insert("errorlog2", $errordata);
