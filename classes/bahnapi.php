<?php

include_once __DIR__ . '/../settings.php';
require_once 'MysqliDb.php';

class bahnapi {

// TODO change keys to private and check if working...
    // current used apikey default first of apikeys
    public $apikeycurrent;
    // all apikeys are in here for use with no limitations
    public $apikeys;
    // if rate limit is reached api key gets removed from this list
    public $apikeyslimited = array();
    //api informations with different endpoints limits are set here too
    public $apis;
    public $errors = array();
    //PLEASE NOTE THIS WILL CREATE AN MEMORY OVERFLOW IF TOO MUCH QUERIES ARE DONE WITH THE SAME OBJECT INSTANCE
    public $calllog = array();
    private $database;

    /**
     * constructor of bahnapi
     * @param array $apikeys like ("key1","key2",....)
     * @return boolean false if error with apikey detected
     */
    public function __construct($apikeys) {
        // set all knows apikeys
        $this->apikeys = $apikeys;

        $this->apikeycurrent = $this->apikeys[0];

        // lookup if apikey is set correctly
        if (strlen($this->apikeycurrent) < 5) {
            trigger_error("Fehler: Kein API-Key angegeben.", 256);
            return false;
        }
        $this->database = new MysqliDb(SETTING_DB_IP, SETTING_DB_USER, SETTING_DB_PASSWORD, SETTING_DB_NAME);
        $this->apis = array("timetables" => array("url" => "https://api.deutschebahn.com/timetables/v1/", "return" => "xml", "limit" => 20), "fahrplan-plus" => array("url" => "https://api.deutschebahn.com/fahrplan-plus/v1/", "return" => "json", "limit" => -1));
    }

    public function getErrorLog() {
        return $this->errors;
    }

    public function addToErrorLog($string) {
        $this->errors[] = time() . " " . $string;
    }

    /**
     *
     * @param string $api current used api ex.  "fahrplan-plus"
     * @return boolean false or string apikey
     */
    public function getNextAPIKey($api) {
        // only add if not already limited
        if(!is_array($this->apikeyslimited[$api])) {
            $this->apikeyslimited[$api] = array();
        }
        if (!in_array($this->apikeycurrent, $this->apikeyslimited[$api])) {
            $this->apikeyslimited[$api][] = $this->apikeycurrent;
        }

        for ($i = 0; $i < count($this->apikeys); $i++) {
            if (in_array($this->apikeys[$i], $this->apikeyslimited[$api])) {
                // already limited
                //$this->errors[] = time() . " already limted: " . $this->apikeys[$i] . " - " . $api;
            } else {
                //not limited for this api till now
                $apikey = $this->apikeys[$i];
//                $this->errors[] = time() . " Found new apikey: " . $apikey . " - " . $api;
                $this->apikeycurrent = $apikey;
                return $apikey;
            }
        }

        // no free key found do some logging maybe
        $this->logErrorToErrorlog("CRITICAL: No free apikey found for: $api", 100);
//        $this->errors[] = time() . " CRITICAL: No free apikey found for: " . $api;
        return FALSE;
    }

    public function convertToFromat($result, $api) {
        if ($this->apis[$api]['return'] == "xml") {
            $xml = simplexml_load_string($result, "SimpleXMLElement", LIBXML_NOCDATA);
            $json = json_encode($xml);
            $array = json_decode($json, TRUE);
        } elseif ($this->apis[$api]['return'] == "json") {
            $array = json_decode($result, TRUE);
        }
        if ($array == FALSE) {
            //$this->errors[] = time() . " API No Fahrt for: " . $api . " params: " . $request;
            $array = "notrains";
        }
        return $array;
    }

    /**
     *
     * @param type $request
     * @param type $api
     * @return boolean
     */
    public function bahnCurl($request, $api) {
//        $this->errors[] = time() . " KKKKKKKKKKKKKKKKKKKKKKKK ";
        $this->calllog[$api][$this->apikeycurrent][] = time();
        if (count($this->calllog[$api][$this->apikeycurrent]) >= $this->apis[$api]['limit']) {
            //$this->errors[] = time() . " Warn: API Limit is reached for APIKEY: " . $this->apikeycurrent . " - API: " . $api . " - APILIMIT: " . $this->apis[$api]['limit'];
            $value = $this->getNextAPIKey($api);
            if ($value == FALSE) {
                return False;
            }
        }
//         var_dump($this->errors);
//        var_dump($this->apikeyslimited);
//        var_dump($this->apikeys);
//        var_dump($this->apikeycurrent);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apis[$api]['url'] . $request);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        $headers = array();
        $headers[] = "Authorization: Bearer " . $this->apikeycurrent;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);

        // debug
        //var_dump($result);
        if (curl_errno($ch)) {
//            trigger_error('Fehler:' . curl_error($ch));
            $errormessage = curl_error($ch);
            $this->logErrorToErrorlog("CURL Fehler: " . $errormessage, 100);
//            $this->errors[] = time() . " CURL Fehler: $errormessage";
            return false;
        }
//        $this->errors[] = time() . " MMMMMMMMMMMMMMMMMMMMMMMMMMMMM ";
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // First level detection, dont update haltestelle here
        if ($http_code == 429 || $result == '{"error":{"code":900800,"message":"You have exceeded your quota","description":"Message throttled out"}}') {
            // API quota warning
            $this->logErrorToErrorlog("API Quota Warning for: " . $api . " APIKEY: " . $this->apikeycurrent, 100);
//            $this->errors[] = time() . " API Quota Warning for: " . $api . " APIKEY: " . $this->apikeycurrent;
            $value = $this->getNextAPIKey($api);
            if ($value == FALSE) {
                return False;
            }
            return FALSE;
        }

        if (strpos($request, 'station') !== false) {
            if ($http_code !== 200) {
                echo "wrong http code $http_code for station missing";
                return FALSE;
            }
        }

        // Filter out random 400 and count up error for this station
        if ($http_code == 400) {
            // API quota warning
            $datahaltestellen = array('fetchtime' => $this->database->now(),
                'errorcount' => $this->database->inc(1));
            $this->database->where('EVA_NR', substr($request, 5, 7));
            if ($this->database->update('haltestellen2', $datahaltestellen)) {
                // echo $this->database->count . ' records were updated';
            } else {
                $this->logErrorToErrorlog("update of haltestellen2 failed: " . $this->database->getLastError(), 100);
//                $this->errors[] = time() . ' update of haltestellen2 failed: ' . $this->database->getLastError();
            }
            return "error400";
        }
        // 400 syntax incorrect, 401 unautorized,  404 happens if too far in history, 410 happens if too far in future, 429 rate limiting see above
        if ($http_code !== 200) {
            $this->logErrorToErrorlog("ERROR: The API returned Code: " . $http_code, 100);
//            $this->errors[] = time() . " ERROR: The API returned Code: " . $http_code;

            $datahaltestellen = array('fetchtime' => $this->database->now(),
                'errorcount' => $this->database->inc(1));
            $this->database->where('EVA_NR', substr($request, 5, 7));
            if ($this->database->update('haltestellen2', $datahaltestellen)) {
                echo $this->database->count . ' records were updated';
            } else {
                $this->logErrorToErrorlog("update of haltestellen2 failed: " . $this->database->getLastError(), 100);
//                $this->errors[] = time() . ' update of haltestellen2 failed: ' . $this->database->getLastError();
            }
            return FALSE;
        }
//        $this->errors[] = time() . " HHHHHHHHHHHHHHHHHHHHHHHHH ";
        // Make
        $array = $this->convertToFromat($result, $api);
        return $array;
    }

    /**
     * TODO maybe handle route update
     * @param type $stationsname
     * @param type $ar
     * @param type $dp
     * @return string of route with | as separator use explode to get array
     */
    public function generatePath($stationsname, $ar, $dp, $geplant = TRUE) {
        $route = "";
        if ($geplant == TRUE) {
            if (isset($ar['@attributes']['ppth'])) {
                $route = $ar['@attributes']['ppth'] . '|';
            }
            $route .= $stationsname;
            if (isset($dp['@attributes']['ppth'])) {
                $route .= '|' . $dp['@attributes']['ppth'];
            }
        } else {
            if (isset($ar['@attributes']['cpth'])) {
                $route = $ar['@attributes']['cpth'] . '|';
            }
            $route .= $stationsname;
            if (isset($dp['@attributes']['cpth'])) {
                $route .= '|' . $dp['@attributes']['cpth'];
            }
        }
        return $route;
    }

    /**
     * parse the data from the api and insert into database
     * @param type $fahrten
     * @param type $abweichungen
     * @return array
     */
    public function parsedata($fahrten, $abweichungen, $time, $evanr) {
        $array = array();

//        var_dump($fahrten);
//        var_dump($abweichungen);
//    print_r($fahrten);

        $stationsname = $fahrten["@attributes"]["station"];
        $stationsevanr = $evanr;
        $datum = date("Y-m-d", $time);
        $cancelstates = array("a" => "added", "c" => "cancelled", "p" => "planned");
        if (!array_key_exists(0, $fahrten["s"])) {
            // nur ein zug mappe diesen um, um den weiteren prozess zu vereinheitlichen
            $temp = $fahrten["s"];
            $fahrten["s"] = array();
            $fahrten["s"][] = $temp;
            // TODO remove debug output after verified
            //var_dump($fahrten["s"]);
        }
        $sortAbweichung = array();
        $noabweichungen = FALSE;
        if (is_array($abweichungen) && array_key_exists("s", $abweichungen)) {
            if (!array_key_exists(0, $abweichungen["s"])) {
                // nur ein zug mappe diesen um, um den weiteren prozess zu vereinheitlichen
                $temp = $abweichungen["s"];
                $abweichungen["s"] = "";
                $abweichungen["s"][] = $temp;
                // TODO remove debug output after verified
//                var_dump($abweichungen["s"]);
            }
            // format abweichungen to be able to map with zugid
            foreach ($abweichungen['s'] as $abw) {
                $sortAbweichung[$abw['@attributes']['id']] = $abw;
            }
        } else {
            $noabweichungen = TRUE;
        }
        $datastrecken = array();
        $datazuege = array();
//        $this->errors[] = time() . " QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ ";
        foreach ($fahrten["s"] as $key => $zugdaten) {
            $zug = array();
            $zugid = $zugdaten["@attributes"]["id"];
            // Grundinformationen zum Zug (Verkehrstyp,Typ,Owner,Klasse,Nummer
            $zugverkehrstyp = $zugdaten['tl']['@attributes']['f'];
            $zugtyp = $zugdaten['tl']['@attributes']['t'];
            $zugowner = $zugdaten['tl']['@attributes']['o'];
            $zugklasse = $zugdaten['tl']['@attributes']['c'];
            $zugnummer = $zugdaten['tl']['@attributes']['n'];
            $zugnummerfull = $zugklasse . $zugnummer;
            $evanr = $stationsevanr;
            // Ankunft
            $ar = NULL;
            $makearnull = FALSE;
            $zugstatus = "";
            // reset platforms
            $gleissoll = "";
            $gleisist = "";
            $linie = "";

            if (isset($zugdaten['ar'])) {
                $ar = $zugdaten['ar'];
                // Zeit
                $arzeitsoll = $ar['@attributes']['pt'];
                if (!$noabweichungen && isset($sortAbweichung[$zugid]['ar']['@attributes']['ct'])) {
                    $arzeitist = $sortAbweichung[$zugid]['ar']['@attributes']['ct'];
                } else {
                    $arzeitist = $arzeitsoll;
                }
                // Gleis
                $gleissoll = $ar['@attributes']['pp'];
                if (!$noabweichungen && isset($sortAbweichung[$zugid]['ar']['@attributes']['cp'])) {
                    $gleisist = $sortAbweichung[$zugid]['ar']['@attributes']['cp'];
                } else {
                    $gleisist = $gleissoll;
                }
                // Linie
                if (isset($ar['@attributes']['l'])) {
                    $linie = $ar['@attributes']['l'];
                }
                // Status
                if (!$noabweichungen && isset($sortAbweichung[$zugid]['ar']['@attributes']['cs'])) {
                    $zugstatus = $cancelstates[$sortAbweichung[$zugid]['ar']['@attributes']['cs']];
                } else {
                    $zugstatus = "n";
                }
            } else {
                $makearnull = TRUE;
            }
            // Abfahrt
            $dp = NULL;
            $makedpnull = FALSE;
            if (isset($zugdaten['dp'])) {
                $dp = $zugdaten['dp'];
                $dpzeitsoll = $dp['@attributes']['pt'];
                if (!$noabweichungen && isset($sortAbweichung[$zugid]['dp']['@attributes']['ct'])) {
                    $dpzeitist = $sortAbweichung[$zugid]['dp']['@attributes']['ct'];
                } else {
                    $dpzeitist = $dpzeitsoll;
                }
                // gleis
                if ($gleissoll == "") {
                    $gleissoll = $dp['@attributes']['pp'];
                    if (!$noabweichungen && isset($sortAbweichung[$zugid]['dp']['@attributes']['cp'])) {
                        $gleisist = $sortAbweichung[$zugid]['dp']['@attributes']['cp'];
                    } else {
                        $gleisist = $gleissoll;
                    }
                }
                // Linie
                if ($linie == "") {
                    if (isset($dp['@attributes']['l'])) {
                        $linie = $dp['@attributes']['l'];
                    }
                }
                if ($zugstatus == "") {
                    if (!$noabweichungen && isset($sortAbweichung[$zugid]['dp']['@attributes']['cs'])) {
                        $zugstatus = $cancelstates[$sortAbweichung[$zugid]['dp']['@attributes']['cs']];
                    } else {
                        $zugstatus = "n";
                    }
                }
            } else {
                $makedpnull = TRUE;
            }
            if (!$makearnull) {
                $arzeitsoll = date("H:i", $this->dateToTimestamp($arzeitsoll));
                $arzeitist = date("H:i", $this->dateToTimestamp($arzeitist));
            } else {
                $arzeitsoll = NULL;
                $arzeitist = NULL;
            }
            if (!$makedpnull) {
                $dpzeitsoll = date("H:i", $this->dateToTimestamp($dpzeitsoll));
                $dpzeitist = date("H:i", $this->dateToTimestamp($dpzeitist));
            } else {
                $dpzeitsoll = NULL;
                $dpzeitist = NULL;
            }
            $route = $this->generatePath($stationsname, $ar, $dp, TRUE); // use geplant
            $hashwert = hash("sha512", $route);
            $hashwertneu = hash("crc32", $route);
            If (!$noabweichungen) {
                $routechanged = $this->generatePath($stationsname, $sortAbweichung[$zugid]['ar'], $sortAbweichung[$zugid]['dp'], FALSE); // use abweichung
            } else {
                $routechanged = $route; // use unchanges
            }
            $hashwertchanged = hash("sha512", $routechanged);
            $hashwertchangedneu = hash("crc32", $routechanged);


            // maybe lookup if routechange is not changed
            $datastrecken[] = array($route, $hashwert, $hashwertneu);
            $datastrecken[] = array($routechanged, $hashwertchanged, $hashwertchangedneu);

            $datazuege[] = array($zugid, $zugverkehrstyp, $zugtyp, $zugowner, $zugklasse, $zugnummer, $zugnummerfull, $linie, $evanr, $arzeitsoll, $arzeitist, $dpzeitsoll, $dpzeitist, $gleissoll, $gleisist, $datum, $hashwertneu, $hashwertchanged, $zugstatus);
        }

        $keysstrecken = array("haltestellen", "hashwert", "hashwertneu");
        foreach ($datastrecken as $strecke) {
            try {
                $streckendata = Array("haltestellen" => $strecke[0],
                    "hashwert" => $strecke[1],
                    "hashwertneu" => $strecke[2]
                );
                $resultstrecken = $this->database->insert("strecken2", $streckendata);
//                echo 'new strecken inserted';
            } catch (Exception $ex) {

            }
        }
        $keyszuege = array("zugid", "zugverkehrstyp", "zugtyp", "zugowner", "zugklasse", "zugnummer", "zugnummerfull", "linie", "evanr", "arzeitsoll", "arzeitist", "dpzeitsoll", "dpzeitist", "gleissoll", "gleisist", "datum", "streckengeplanthash", "streckenchangedhash", "zugstatus");
        $datahaltestellen = array();
        $counterrors = 0;
        foreach ($datazuege as $zug) {
            try {
                $zuegedata = array("zugid" => $zug[0],
                    "zugverkehrstyp" => $zug[1],
                    "zugtyp" => $zug[2],
                    "zugowner" => $zug[3],
                    "zugklasse" => $zug[4],
                    "zugnummer" => $zug[5],
                    "zugnummerfull" => $zug[6],
                    "linie" => $zug[7],
                    "evanr" => $zug[8],
                    "arzeitsoll" => $zug[9],
                    "arzeitist" => $zug[10],
                    "dpzeitsoll" => $zug[11],
                    "dpzeitist" => $zug[12],
                    "gleissoll" => $zug[13],
                    "gleisist" => $zug[14],
                    "datum" => $zug[15],
                    "streckengeplanthash" => $zug[16],
                    "streckenchangedhash" => $zug[17],
                    "zugstatus" => $zug[18]);
                $resultzuege = $this->database->insert("zuege2", $zuegedata);
//                echo 'new strecken inserted';
                if (!$resultzuege) {
                    $errorstring = $this->database->getLastError();
                    $duplicatezugid = "for key 'zugid'";
                    $pos = strpos($errorstring, $duplicatezugid);
                    if ($pos === false) {
                        // not a duplicate error of db
                        $counterrors++;
                        $this->logErrorToErrorlog("Insert of one zug failed:: " . $this->database->getLastError(), 100);
//                        $this->errors[] = time() . "Insert of one zug failed: " . $this->database->getLastError();
                    }
                }
            } catch (Exception $ex) {
                $errorstring = $this->database->getLastError();
                $duplicatezugid = "for key 'zugid'";
                $pos = strpos($errorstring, $duplicatezugid);
                if ($pos === false) {
                    // not a duplicate error of db
                    $counterrors++;
                    $this->logErrorToErrorlog("Insert of one zug failed:: " . $this->database->getLastError(), 100);
//                    $this->errors[] = time() . "Insert of one zug failed: " . $this->database->getLastError();
                }
            }
        }

        if ($counterrors > 0) {
            $datahaltestellen = array('fetchtime' => $this->database->now(),
                'errorcount' => $this->database->inc(1));
        } else {
            $datahaltestellen = array('fetchtime' => $this->database->now(),
                'successcount' => $this->database->inc(1));
        }
        $this->database->where('EVA_NR', $stationsevanr);
        $haltestellenresult = $this->database->update('haltestellen2', $datahaltestellen);
        if (!$haltestellenresult) {
            $this->logErrorToErrorlog("update of haltestellen2 failed: " . $this->database->getLastError(), 100);
//            $this->errors[] = time() . ' update of haltestellen2 failed: ' . $this->database->getLastError();
        } else {
//            $this->errors[] = time() . ' update of haltestellen2 : '.  $this->database->count . ' records were updated';
        }
//$this->errors[] = time() . " TTTTTTTTTTTTTTTTTTTTTTTTT ";
        return $array;
    }

    /**
     * this function gets the current timetable of a station
     * @param int $stationID as evanr
     * @param int $time timestamp
     * @return array TODO implement me
     */
    public function getTimetable($stationID, $time = 0) {
//        $this->errors[] = time() . " AAAAAAAAAAAAAAAAAAAAAAAAAAAAA. ";
        // Set time if not given
        if ($time == 0) {
            $time = time();
        }
        // resolve params
        $date = date("ymd", $time);
        $hour = date("H", $time - 0);
        // generate request fahrten
        $requestFahrten = 'plan/' . $stationID . '/' . $date . '/' . $hour;
        $fahrten = $this->bahnCurl($requestFahrten, "timetables");
//        $this->errors[] = time() . " BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB ";
        // lookup errors
        if ($fahrten == FALSE) {
            $this->logErrorToErrorlog("Error getting Fahrten skipping next request: " . $stationID . '/' . $date . '/' . $hour, $stationID);
//            $this->errors[] = time() . " Error getting Fahrten skipping next request. " . $stationID . '/' . $date . '/' . $hour;
            return FALSE;
        }
        if ($fahrten == "error400") {
            // haltestellenupdate ist bereits gemacht, gib keinen fehler aus da errocount+1
            return FALSE;
        }
        if ($fahrten == "notrains") {
//            $this->errors[] = time() . " Kein Zug an dieser Haltestelle. " . $stationID . '/' . $date . '/' . $hour;
            // Update haltestellen2 so we move on.
            $datahaltestellen = array('fetchtime' => $this->database->now(), 'successcount' => $this->database->inc(1), 'notraincount' => $this->database->inc(1));
            $this->database->where('EVA_NR', $stationID);
            if ($this->database->update('haltestellen2', $datahaltestellen)) {
//                echo $this->database->count . ' records were updated';
            } else {
                $this->logErrorToErrorlog("Update of haltestellen2 failed: " . $this->database->getLastError(), $stationID);

//                $this->errors[] = time() . ' update of haltestellen2 failed: ' . $this->database->getLastError();
            }
            return FALSE;
        }
//        var_dump($fahrten);
        // generate request abweichungen
        $requestAbweichung = "fchg/" . $stationID;
        $abweichungen = $this->bahnCurl($requestAbweichung, "timetables");
        if ($abweichungen == FALSE) {
            $this->logErrorToErrorlog("Error getting Abweichungen returning now ", $stationID);
//            $this->errors[] = time() . " Error getting Abweichungen returning now. " . $stationID;
            return FALSE;
        }
//        var_dump($abweichungen);
        // TODO check if abweichungen is valid?
        // parseresults and return as array
//$this->errors[] = time() . " CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC ";
        $zuege = $this->parsedata($fahrten, $abweichungen, $time, $stationID);
//        $this->errors[] = time() . " DDDDDDDDDDDDDDDDDDDDDDDDDDDDD ";
        return $zuege;
    }

    /**
     * User to convert timestamps between Bahn and default timestamp
     * @param dateformat $bahndatum ymdHi
     * @return timestamp of the bahndate
     */
    public function dateToTimestamp($bahndatum) {
        $date = DateTime::createFromFormat('ymdHi', $bahndatum);
        return date_timestamp_get($date);
    }

    public function logErrorToErrorlog($errorstring, $evanr = 100) {
        $errordata = array("log" => $errorstring, "evanr" => $evanr);
        $this->database->insert("errorlog2", $errordata);
    }

    public function getStationData($haltestellenname) {

        // what works currently

        // Groningen Europapark     Groningen%20Europapark
        // Varnsdorf mlékárna       Varnsdorf%20ml%25C3%25A9k%25C3%25A1rna
        // Hainewalde Berghäuser    Hainewalde%20Bergh%25C3%25A4user
        // Prösen Ost B101          Pr%25C3%25B6sen%20Ost%20B101
        // Teisnach Rohde+Schwarz   Teisnach%20Rohde%252BSchwarz
        // Buschmühle               Buschm%25C3%25BChle
        // Ulberndorf               Ulberndorf
        // Hojsova Straz-Brcalnik   Hojsova%20Straz-Brcalnik




        $namewithoutsomechars = $haltestellenname;

        $invalidchars = array("/", "�");
        $namewithoutsomechars = str_replace($invalidchars, "?", $namewithoutsomechars);
//        $namewithoutsomechars = str_replace("? ", "?", $namewithoutsomechars);
//        $invalidchars = array(" ");
//        $namewithoutsomechars = str_replace($invalidchars, "%20", $namewithoutsomechars);

//        while (true) {
//            $tmpnameloop = $namewithoutsomechars;
//
//            foreach ($invalidchars as $char) {
//                $namewithoutsomechars = explode("$char", $namewithoutsomechars)[0];
//            }
//
//            if ($tmpnameloop == $namewithoutsomechars) {
//                break;
//            }
//        }

//        var_dump($haltestellenname);
//        $tmpname = explode(",", $haltestellenname);
//        $haltestellennamefirstpart = $tmpname[0];
//        $tmpname2 = explode("ß", $haltestellennamefirstpart);
//        $haltestellennamefirstpart2 = $tmpname2[0];

        $invalidchars = array("(");
        $namewithoutsomechars = str_replace($invalidchars, "%28", $namewithoutsomechars);
        $invalidchars = array(")");
        $namewithoutsomechars = str_replace($invalidchars, "%29", $namewithoutsomechars);

        $invalidchars = array("ß");
        $namewithoutsomechars = str_replace($invalidchars, "%3F", $namewithoutsomechars);

        $encodedName = urlencode(trim($namewithoutsomechars));
//        $encodedName = urlencode($haltestellenname);
        $invalidchars = array("%");
        $encodedName = str_replace($invalidchars, "%25", $encodedName);
        $invalidchars = array("%2525");
        $encodedName = str_replace($invalidchars, "%25", $encodedName);
        $invalidchars = array("+");
        $encodedName = str_replace($invalidchars, "%20", $encodedName);
        echo "Sending request for: ".$encodedName. "<br>";
        // generate request fahrten
        $requestFahrten = 'station/' . $encodedName;
        $station = $this->bahnCurl($requestFahrten, "timetables");

        if($station == false) {
            echo "Got error for: ".$encodedName. "<br>";
            return false;
        }

//        var_dump($station);
        // result
        // array(1) { ["station"]=> array(1) { ["@attributes"]=> array(5) { ["name"]=> string(15) "Mosonmagyarovar" ["eva"]=> string(7) "5500016" ["ds100"]=> string(4) "XMMO" ["db"]=> string(4) "true" ["creationts"]=> string(21) "19-06-09 02:14:17.145" } } }
        if (is_array($station)) {
//            if (isset($station["stations"])) {
//                $station["station"] = $station["stations"];
//            }
            if (isset($station["station"])) {
                $rawstation = $station["station"];
//                var_dump($rawstation);
                if (isset($rawstation["@attributes"])) {
                    $stationdata = $rawstation["@attributes"];
//                    var_dump($stationdata);
                    if (isset($stationdata["name"])) {
                        if (isset($stationdata["eva"])) {
                            if (isset($stationdata["ds100"])) {
                                // nice if pyramid completed :D
                                $haltestellendaten = Array("NAME" => $stationdata['name'],
                                    "EVA_NR" => (int)$stationdata['eva'],
                                    "DS100" => $stationdata['ds100'],
                                    "fetchactive2" => 1,
                                    "manualadded" => 1,
                                    "country_code" => ""
                                );
                                $resulthaltestelleninsert = $this->database->insert("haltestellen2", $haltestellendaten);
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    }

}
