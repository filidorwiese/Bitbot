#!/usr/bin/php
<?php

chdir(dirname(__FILE__));
require_once('./Config.php');
require_once('./PHP-btce-api/btce-api.php');

// Connect to BTC-e
$BTCeAPI = new BTCeAPI($CONFIG['btce_api_key'], $CONFIG['btce_api_secret']);

// Start with buying
$lastAction = 'sell';
$minimumProfitableSell = -1;

while (true) {
    // Get ticker status for trading pair
    $ticker = getTicker($CONFIG['trade_pair']);

    // Get balance for trading pair
    list($currency1, $currency2) = explode('_', $CONFIG['trade_pair']);
    $balance = getAccountBalance($currency1, $currency2);
    $accountValue = ($balance[$currency1] * $ticker['last']) + $balance[$currency2];

    // Calculate average/threshold/etc
    $average = $ticker['avg'];
    $current = $ticker['last'];
    $tradeAmount = $balance[$currency1] * $CONFIG['trade_amount'];
    $tradeThreshold = ($average * $CONFIG['trade_threshold']);
    $buyThreshold = $average - $tradeThreshold;
    $sellThreshold = $average + $tradeThreshold;

    // Print status
    echo 'Trade pair: ' . strtoupper($CONFIG['trade_pair']) . PHP_EOL;
    echo 'Trade amount: ' . $tradeAmount . ' ' . $currency1 . PHP_EOL;
    echo 'Trade threshold: ' . $tradeThreshold . ' ' . $currency1 . PHP_EOL;
    echo 'Account balance: ' . $balance[$currency1] . ' ' . $currency1 . ' ' . $balance[$currency2] . ' ' . $currency2 . ' (value: ' . $accountValue . ' ' . $currency2 . ')' . PHP_EOL . PHP_EOL;
    echo 'Average: ' . $average . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
    echo 'Current: ' . $current . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
    echo 'Buying threshold: ' . $buyThreshold . ' ' . $currency2 . PHP_EOL;
    echo 'Selling threshold: ' . $sellThreshold . ' ' . $currency2 . PHP_EOL;

    // Alternate between buying and selling
    if ($lastAction == 'sell') {
	echo 'Last action: ' . $lastAction . PHP_EOL;
        if ($current <= $buyThreshold) {
            $cost = ($tradeAmount * $current);
            echo 'BUYING: ' . $tradeAmount . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . PHP_EOL;
            if ($balance[$currency1] < $tradeAmount) {
                echo 'Insufficient ' . $currency2 . ' funds to buy' . PHP_EOL;
            } else {
		trade($CONFIG['trade_pair'], $currency1, $currency2, 'buy');
            }
        }
    } else {
	echo 'Last action: ' . $lastAction . PHP_EOL;
	echo 'Minimum profitable sell: ' . $minimumProfitableSell . ' ' . $currency2 . PHP_EOL;
        if ($current >= $sellThreshold && $current > $minimumProfitableSell) {
            $cost = ($tradeAmount * $current);
            echo 'SELLING: ' . $tradeAmount . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . PHP_EOL;
            if ($balance[$currency2] < $cost) {
                echo 'Insufficient ' . $currency1 . ' funds to sell' . PHP_EOL;
            } else {
		trade($CONFIG['trade_pair'], $currency1, $currency2, 'sell');
            }
        }
    }

    // Wait for a while
    echo str_repeat('-', 80) . PHP_EOL;
    sleep($CONFIG['trade_wait']);
}





/*
    [success] => 1
    [return] => Array
        (
            [funds] => Array
                (
                    [usd] => 316.30022399
                    [btc] => 0.00372
                    [ltc] => 16.39505829
                    [nmc] => 0
                    [rur] => 0
                    [eur] => 0
                    [nvc] => 0
                    [trc] => 0
                    [ppc] => 0
                    [ftc] => 0
                    [xpm] => 0
                )

            [rights] => Array
                (
                    [info] => 1
                    [trade] => 1
                    [withdraw] => 0
                )

            [transaction_count] => 146
            [open_orders] => 0
            [server_time] => 1386611584
        )
*/
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
    }
}




/*
   [ticker] => Array
        (
            [high] => 32.76
            [low] => 24.6
            [avg] => 28.68
            [vol] => 35202863.31842
            [vol_cur] => 1202006.83998
            [last] => 30.75
            [buy] => 30.74999
            [sell] => 30.7
            [updated] => 1386610702
            [server_time] => 1386610703
        )
*/
function getTicker($trade_pair = 'btc_usd') {
    global $BTCeAPI;

    // Fake it
    //return array('last' => 30.00009,'avg' => 32);

    // For real
    try {
        $ticker = $BTCeAPI->getPairTicker($trade_pair);
        return $ticker['ticker'];
        //print_r($ticker);
    } catch(BTCeAPIException $e) {
        echo $e->getMessage();
    }
}




function trade($trade_pair, $currency1, $currency2, $buyOrSell = 'sell') {
    global $BTCeAPI, $lastAction, $minimumProfitableSell;

    try {
        //$BTCeAPI->makeOrder($amount, $pair, $direction, $price);
        $BTCeAPI->makeOrder($currency1, $trade_pair, $buyOrSell, $currency2);
        $lastAction = $buyOrSell;
        $minimumProfitableSell = $currency2;
    } catch(BTCeAPIInvalidParameterException $e) {
        echo $e->getMessage();
    } catch(BTCeAPIException $e) {
        echo $e->getMessage();
    }
}
