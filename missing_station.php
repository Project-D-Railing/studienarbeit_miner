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
$fetchcount = (int)(floor(count($apikeys) / 2) - 1) * 18;
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
$limit = 5000;
$totalqueries = 0;
$streckenquery = $mysqlislave->query("SELECT * FROM strecken2 WHERE id >= $row_id ORDER BY id ASC LIMIT $limit");

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
            $haltestelleutf8 = utf8_encode($haltestelle);
            echo "Missing $haltestelleutf8 <br>";
            $result = $bahnapi->getStationData($haltestelle);
            if($result == true) {
                echo "Found Missing $haltestelle and added to table <br>";
            } else {
                echo "Didn't find missing $haltestelle was not added to table<br>";
            }
            $totalqueries++;

            $stationen[] = $haltestelle;
            usleep(mt_rand(200000, 1500000));
        }
    }
    $mysqlislave->query("UPDATE current_state SET row_id = '$haltestellenid' where id = 1");
}

echo "Done";
