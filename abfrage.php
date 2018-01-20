<?php
/*
 * This script queries the bahnapi and inserts the found datasets to our database.
 * It has error handling and can switch between multiple api keys to avoid restirctions. 
 * It will only query each station once per hour and it will update with bahn like delay of 2 hours 
 */

include('settings.php');
require_once 'classes/bahnapi.php';
require_once 'classes/MysqliDb.php';

// currently not used maybe later
//require_once 'classes/dbObject.php.php';


for ($i = 0; $i < count(SETTING_APIKEYS); $i++) { 
    echo $i ."<br>";
}


/* Ablauf
 * 
 * 1. Holen der noch nicht aktuellen Stationen
 * LOOP
 * 2. Station abfragen
 * 3. Station in DB schreiben
 * LOOP END
 */