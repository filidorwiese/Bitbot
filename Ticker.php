#!/usr/bin/php
<?php

chdir(dirname(__FILE__));
require_once('./btce-api/btce-api.php');
require_once('profiles/ticker.profile.php');

// Connect to MySQl
$mysqli = new mysqli($CONFIG['mysql_host'], $CONFIG['mysql_user'], $CONFIG['mysql_password'], $CONFIG['mysql_database']);
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . PHP_EOL;
    exit;
}

// Connect to BTC-e
$BTCeAPI = new BTCeAPI($CONFIG['btce_api_key'], $CONFIG['btce_api_secret'], $CONFIG['btce_nonce_file']);

// Get Ticker
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

    // Save to database
    $result = $mysqli->query("INSERT INTO  `bitbot`.`ticker` (`ltc_usd`,`btc_usd`,`timestamp`) VALUES ('" . $tickerLtcUsd['ticker']['buy'] . "', '" . $tickerBtcUsd['ticker']['buy'] . "', '" . time() . "');");
    if (!$result) { echo "MySQL error: " . $mysqli->error . PHP_EOL; exit; }

    // Clean database
    $result = $mysqli->query("DELETE FROM `bitbot`.`ticker` WHERE `ticker`.`timestamp` < " . (time() - (60*60*24*2)) );
    if (!$result) { echo "MySQL error: " . $mysqli->error . PHP_EOL; exit; }

//DELETE FROM `bitbot`.`ticker` WHERE `ticker`.`id` = 1
} catch(BTCeAPIException $e) {
    echo $e->getMessage();
}


// Get account balance
try {
    $accountInfo = $BTCeAPI->apiQuery('getInfo');
    $ltc = $accountInfo['return']['funds']['ltc'];
    $btc = $accountInfo['return']['funds']['btc'];
    $usd = $accountInfo['return']['funds']['usd'];

    // Save to database
    $result = $mysqli->query("UPDATE `bitbot`.`balance` SET `ltc` = '" . $ltc . "', `btc` = '" . $btc . "', `usd` = '" . $usd . "', `timestamp` = '" . time() . "' WHERE `balance`.`id` = 1;");
    if (!$result) { echo "MySQL error: " . $mysqli->error . PHP_EOL; exit; }
} catch(BTCeAPIException $e) {
    echo $e->getMessage();
}









function getAccountBalance($currency1, $currency2) {
    global $BTCeAPI;

    // Fake it
    //return array('ltc' => 50, 'usd' => 320);

    // For real
    try {
        $accountInfo = $BTCeAPI->apiQuery('getInfo');
        $amount1 = $accountInfo['return']['funds'][$currency1];
        $amount2 = $accountInfo['return']['funds'][$currency2];

        return array(
            $currency1 => floatval($amount1),
            $currency2 => floatval($amount2)
        );
    } catch(BTCeAPIException $e) {
        echo $e->getMessage();
        return false;
    }
}
