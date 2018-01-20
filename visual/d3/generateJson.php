<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
$links = array();
$nodes = array();
for ($i = 0; $i < 500;$i++) {
    $links[] = array("source" => $i, "target" => $i+1);
    $nodes[] = array("size" => mt_rand(20, 60), "score" => mt_rand(0, 100)/100, "id" => "Haaallo","type" => "circle");
} 
$nodes[] = array("size" => mt_rand(20, 60), "score" => mt_rand(0, 100)/100, "id" => "Haaallo","type" => "circle");
$nodes[] = array("size" => mt_rand(20, 60), "score" => mt_rand(0, 100)/100, "id" => "Haaallo","type" => "circle");
$graph = array();
//$links = array(array("source" => 0, "target" => 1),array("source" => 1, "target" => 2));
//$nodes = array(array("size" => 40, "score" => 0, "id" => "Haaallo","type" => "circle"),array("size" => 40, "score" => 0, "id" => "YYYYYYYYY","type" => "circle"),array("size" => 60, "score" => 0, "id" => "RRRRRRRRRR","type" => "circle"));
$directed = FALSE;
$multigraph = FALSE;
$array = array("graph" => $graph, "links" => $links, "nodes" => $nodes, "directed" => $directed, "multigraph" => $multigraph);


$fp = fopen('graph.json', 'w');
fwrite($fp, json_encode($array));   //here it will print the array pretty
fclose($fp);