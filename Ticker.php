#!/usr/bin/php
<?php

chdir(dirname(__FILE__));
require_once('./Config.php');
require_once('./PHP-btce-api/btce-api.php');

// Connect to MySQl
$mysqli = new mysqli($CONFIG['mysql_host'], $CONFIG['mysql_user'], $CONFIG['mysql_password'], $CONFIG['mysql_database']);
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . PHP_EOL;
    exit;
}

// Connect to BTC-e
$BTCeAPI = new BTCeAPI($CONFIG['btce_api_key'], $CONFIG['btce_api_secret']);

// Example getInfo
try {
    /*
    [ticker] => Array
        (
            [high] => 32.76
            [low] => 24.6
            [avg] => 28.68
            [vol] => 35240071.99188
            [vol_cur] => 1203974.38994
            [last] => 30.8
            [buy] => 30.8
            [sell] => 30.77
            [updated] => 1386609891
            [server_time] => 1386609892
        )
    */
    $tickerLtcUsd = $BTCeAPI->getPairTicker('ltc_usd');
    $tickerBtcUsd = $BTCeAPI->getPairTicker('btc_usd');

    //print_r($BTCeAPI->getPairDepth('ltc_usd'));
    //print_r($BTCeAPI->getPairFee('ltc_usd'));
    //print_r($BTCeAPI->getPairTicker('ltc_usd'));
    // Perform the API Call
    //$getInfo = $BTCeAPI->apiQuery('getInfo');
    // Print so we can see the output
    //print_r($getInfo);
} catch(BTCeAPIException $e) {
    echo $e->getMessage();
}


// Save to database
$result = $mysqli->query("INSERT INTO  `bitbot`.`ticker` (`ltc_usd`,`btc_usd`) VALUES ('" . $tickerLtcUsd['ticker']['last'] . "', '" . $tickerBtcUsd['ticker']['last'] . "');");
if (!$result) { echo "MySQL error: " . $mysqli->error . PHP_EOL; exit; }
