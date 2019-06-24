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
$hours = date("G", time());

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

//reset state every day at 2 15
if($minute == 15 && $hours == 2) {
    $mysqlislave->query("UPDATE current_state SET row_id = '1' where id = 1");
}


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
            $result = $bahnapi->getStationData($haltestelleutf8);
            if($result == true) {
                echo "Found Missing $haltestelleutf8 and added to table <br>";
            } else {
                echo "Didn't find missing $haltestelleutf8 was not added to table<br>";
            }
            $totalqueries++;

            $stationen[] = $haltestelle;
            usleep(mt_rand(200000, 1500000));
        }
    }
    $mysqlislave->query("UPDATE current_state SET row_id = '$haltestellenid' where id = 1");
}
$mysqlislave->query('update `haltestellen2` set country_code="DE" where EVA_NR < 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="RU" where EVA_NR like "20%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="BY" where EVA_NR like "21%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="PL" where EVA_NR like "51%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="CZ" where EVA_NR like "54%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="HU" where EVA_NR like "55%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="SK" where EVA_NR like "56%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="ES" where EVA_NR like "71%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="SI" where EVA_NR like "79%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="DE" where EVA_NR like "80%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="AT" where EVA_NR like "81%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="LU" where EVA_NR like "82%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="IT" where EVA_NR like "83%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="NL" where EVA_NR like "84%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="CH" where EVA_NR like "85%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="DK" where EVA_NR like "86%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="FR" where EVA_NR like "87%" and EVA_NR > 1000000;');
$mysqlislave->query('update `haltestellen2` set country_code="BE" where EVA_NR like "88%" and EVA_NR > 1000000;');


echo "Done";
