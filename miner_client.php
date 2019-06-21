<?php
include_once './settings.php';
require_once 'classes/bahnapi.php';
require_once 'classes/MysqliDb.php';


/** the client will get settings like
 *  - APIKEY (xxx per client to run mine xxx stations per minute)
 *  - MYSQL credentials to fetch work and submit results
 */

if(isset($_REQUEST['workerid'])) {
    $workerid = $_REQUEST['workerid'];
} else {
    $workerid = 1;
}



$apikeys = SETTING_APIKEYS[$workerid];

/**
 * 4 = 1 * 8
 * 6 = 2 * 8
 * 8 = 3 * 8
 * 10 = 4 * 8
 */
$fetchcount = (int)(floor(count($apikeys) / 2) - 1) * 9;
// client time is only used to select keys so no problem with different timezones
$minute = date("i", time());
if ($minute % 2 == 0) {
    $apikeys = array_reverse($apikeys);
}
// script path to find easier
$filepath = $_SERVER["SCRIPT_FILENAME"];
$currenthost = $_SERVER["HTTP_HOST"];

// create objects
$bahnapi = new bahnapi($apikeys);
$mysqlislave = new mysqli(SETTING_DB_IP, SETTING_DB_USER, SETTING_DB_PASSWORD, SETTING_DB_NAME);
$db = new MysqliDb(SETTING_DB_IP, SETTING_DB_USER, SETTING_DB_PASSWORD, SETTING_DB_NAME);


if ($mysqlislave->query("START TRANSACTION;") === false) {
    die("Could not start transaction.");
}


$stationsquery = $mysqlislave->query("SELECT EVA_NR as nr, NAME, fetchtime FROM haltestellen2 WHERE fetchactive2=1 AND fetchstatus = 1 AND fetchtime < (NOW() - INTERVAL 60 MINUTE) ORDER BY fetchtime ASC LIMIT 0,$fetchcount FOR UPDATE ");
$stationen = array();
while ($row = $stationsquery->fetch_assoc()) {
    $evanr = $row['nr'];
    $stationen[] = array('nr' => $row['nr'], 'name' => $row["NAME"]);
    $mysqlislave->query("UPDATE haltestellen2 SET fetchstatus = 2 WHERE EVA_NR = '$evanr'");
}

if ($mysqlislave->query("COMMIT;") === false) {
    die("Could not commit transaction.");
}

$timestamp = time();
$errordata = array("log" => "Start fetching $fetchcount stations from $currenthost with $filepath my current time is $timestamp", "evanr" => 100);
$db->insert("errorlog2", $errordata);

//$bahnapi->addToErrorLog("Anz. Fetch: " . count($stationen));
// wait between 1 and 10 seconds before using api
usleep(mt_rand(1000000, 10000000));
foreach ($stationen as $key => $station) {
    $starttime = microtime(true);
    // get all trains and delays for station
    $offset = 60 * 60 * 2;  // delay apicall by 2 hours to ensure data is correct
    $timeold = time() - 7200;
    $zuege = $bahnapi->getTimetable($station['nr'], $timeold);


    // set fetchstatus back to normal
    $evanr = $station['nr'];
    $mysqlislave->query("UPDATE haltestellen2 SET fetchstatus = 1 WHERE EVA_NR = '$evanr'");
    $totaltime = round(((microtime(true) - $starttime)), 2);
    $errordata = array("log" => "Done in $totaltime seconds $currenthost", "evanr" => $evanr);
    $db->insert("errorlog2", $errordata);
    usleep(500000);
}

$errordata = array("log" => "Finished fetching $fetchcount stations from $currenthost with $filepath", "evanr" => 100);
$db->insert("errorlog2", $errordata);

