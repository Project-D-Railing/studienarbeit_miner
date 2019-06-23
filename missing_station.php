<?php
include_once './settings.php';
require_once 'classes/bahnapi.php';
require_once 'classes/MysqliDb.php';

echo "Start <br>";

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

$stationsquery = $mysqlislave->query("SELECT EVA_NR as nr, NAME FROM haltestellen2");
$stationen = array();
while ($row = $stationsquery->fetch_assoc()) {
    $stationen[] = $row["NAME"];
}
//var_dump($stationen);

$current_state = $mysqlislave->query("SELECT row_id FROM current_state where id = 1");
$row = $current_state->fetch_assoc();
$row_id = $row['row_id'];
$limit = 1000;
$totalqueries = 0;
$streckenquery = $mysqlislave->query("SELECT * FROM strecken2 ORDER BY ID ASC LIMIT $row_id, $limit");

while ($row = $streckenquery->fetch_assoc()) {
    $haltestellen = array();
    //var_dump($row['haltestellen']);
    $haltestellenstring = $row['haltestellen'];
    $haltestellenid = $row['id'];
    $haltestellen = explode("|", $haltestellenstring);
    foreach ($haltestellen as $haltestelle) {
        //var_dump($haltestelle);
        if (!in_array($haltestelle, $stationen)) {
            if($totalqueries >= $fetchcount) {
                // skip fetch if we already use all of our apikeys
                continue 2;
            }
            echo "Missing $haltestelle <br>";
            $stationdata = $bahnapi->getStationData($haltestelle);
            $totalqueries++;
            $evanr = $stationdata['evanr'];
            $ds100 = $stationdata['ds100'];
            $name = $stationdata['name'];
            var_dump($stationdata);
//            $mysqlislave->query("INSERT INTO haltestellen2 (EVA_NR, DS100, NAME, fetchactive2, manualadded) VALUE ($evanr,$ds100, $name, 0, 1)");

            $stationen[] = $haltestelle;
        }
    }
    $mysqlislave->query("UPDATE current_state SET row_id = '$haltestellenid' where id = 1");
}

echo "Done";
