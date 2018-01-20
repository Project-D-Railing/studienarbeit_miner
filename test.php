<?php

include_once './settings.php';

require_once 'classes/bahnapi.php';

require_once 'classes/MysqliDb.php';
require_once 'classes/appgati.php';
// currently not used maybe later
//require_once 'classes/dbObject.php';
//$app = new AppGati();
//$app->Step(0);

$apikeys = SETTING_APIKEYSTEST;
$apikeys2 = array_reverse($apikeys);
$minute = date("i", time());

if ($minute % 2 == 0) {
    $bahnapi = new bahnapi($apikeys);
} else {
    $bahnapi = new bahnapi($apikeys2);
}


// Using old querie here because limit dosnt seem to work in rawquery
$params = date("Y-m-d H:i:s", time() - 3600);
$mysqlislave = new mysqli(SETTING_DB_IP, SETTING_DB_USER, SETTING_DB_PASSWORD, SETTING_DB_NAME);

if($minute == 0 || $minute == "00" || $minute == "0") {
    $stationsquery = $mysqlislave->query("UPDATE haltestellen2 set fetchtime='2017-12-01 00:00:00'"); // all stations should be fetched
    
    // Insert twitter fetch here last 200 tweets lasted over 30 days... 
    
    
}

$stationsquery = $mysqlislave->query("SELECT EVA_NR as nr, NAME FROM haltestellen2 WHERE fetchactive2=1 AND fetchtime < '$params' ORDER BY fetchtime ASC LIMIT 0,135");

$station = array();
while ($row = $stationsquery->fetch_assoc()) {
    $station[] = array('nr' => $row['nr'],'name' => $row["NAME"]);
}
$stationen = $station; // override temporaly to debug
$bahnapi->addToErrorLog("Anz. Fetch: " .count($station));

// richtwert fuer die anzahl der bahnhoefe ist floor( anzahl keys durch 2)*10  dh 9-> 4*10 = 40  , 30 -> 15*10 = 150 --> bissel da max 6605/60 = 
//$anzahlapikeysneeded = ceil((count($stationen) / 60)/10); // durch 10 da immer 2 calls pro station benötigt werden.
$anzahlapikeysneeded = ceil(((count($stationen)) / 10) * 2); // durch 10 da immer 2 calls pro station benötigt werden. nur die hälfte wird jeweis genutzt
echo "Es werden mindestens: " . $anzahlapikeysneeded . " APIKEYS benötigt<br>";
echo "Es sind: " . count(SETTING_APIKEYSTEST) . " TestAPIKEYS hinterlegt<br>";
echo "Es sind: " . count(SETTING_APIKEYS) . " APIKEYS hinterlegt<br>";
// insgesamt: 6605 stationen davon momentan 1128 momentan
// 
// 6600/ 60 = 110 pro minute -> 11 API keys da limit 20 und 2x pro station
//echo '<pre><br>';
//print_r($stationen); // contains Array of returned rows
//echo '</pre><br>';

usleep(5000000);
$i = 1;
//$bahnapi->addToErrorLog(json_encode($stationen));
foreach ($stationen as $key => $station) {


    // ACHTUNG: FOLGENDER BUG:
    // Kandel:  7719666547733852727   kommt doppelt vor obwohl der gleiche zug. da vor und nach der ganzen stunde aktionen stattfinden. es sollte auf doppelte geprüft werden.
//    echo "Start Durchlauf: " . $i . "<br>";
//    echo $station['nr'] . " - " . $station['name'] . "<br>";
    $timeold = time() - 7200;
    $zuege = $bahnapi->getTimetable($station['nr'], $timeold);
//    echo "Ende Durchlauf: " . $i . "<br>";
    $i++;
    $string = "Station: " . $station['nr'] . " fetched";
//    $bahnapi->addToErrorLog($string);
    //    echo '<pre><br>';
//    print_r($app->Report($i-1, $i));
//    echo '</pre><br>';
}

$db = new MysqliDb(SETTING_DB_IP, SETTING_DB_USER, SETTING_DB_PASSWORD, SETTING_DB_NAME);

$errors = json_encode($bahnapi->getErrorLog());
$errordata = array("log" => $errors);
$db->insert("errorlog2", $errordata);

//var_dump($bahnapi->getErrorLog());



// TODO ignore this or delete later
 
 /*
  * <?xml version='1.0' encoding='UTF-8'?>
<timetable station='Kusel'>
  <s id="-5809879269975775657-1711292036-18">
    <tl f="N" t="p" o="06" c="RB" n="12896"/>
    <ar pt="1711292141" pp="" l="67" ppth="Kaiserslautern Hbf|Kennelgarten|Vogelweh|Einsiedlerhof|Kindsbach|Landstuhl|Ramstein|Miesenbach|Steinwenden|Obermohr|Niedermohr|Glan-M&#252;nchweiler|Rehweiler|Matzenbach|Theisbergstegen|Altenglan|Rammelsbach"/>
  </s>
</timetable>
  */