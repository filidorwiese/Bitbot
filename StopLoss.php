#!/usr/bin/php
<?php

chdir(dirname(__FILE__));
require_once('./Config.php');
require_once('./PHP-btce-api/btce-api.php');

// Connect to BTC-e
$BTCeAPI = new BTCeAPI($CONFIG['btce_api_key'], $CONFIG['btce_api_secret']);

// Connect to MySQl
$mysqli = new mysqli($CONFIG['mysql_host'], $CONFIG['mysql_user'], $CONFIG['mysql_password'], $CONFIG['mysql_database']);
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . PHP_EOL;
    exit;
}


$bought = false;
$trailing_stop_margin = 0;
$stop_loss_percentage = 95;
$sma_short_minutes = 10;
$sma_long_minutes = 120;
$last_price = 0;
$buy_price = 0;
$buy_threshold = 0.25;
$updateAccountBalance = 0;

$simulation = (isset($argv[1]) && $argv[1] === 'real' ? false : true);

while (true) {
    // Get ticker status for trading pair
    $ticker = getTicker($CONFIG['trade_pair']);
    if ($ticker === false) { sleep($CONFIG['trade_wait']); continue; }

    // Get balance for trading pair
    list($currency1, $currency2) = explode('_', $CONFIG['trade_pair']);
    $balance = $mysqli->query("SELECT * FROM balance LIMIT 1")->fetch_assoc();
    $accountValue = ($balance[$currency1] * $ticker['sell']) + $balance[$currency2];

    // Calculate average/threshold/etc
    $SmaShort = floatval($mysqli->query("SELECT AVG(x." . $CONFIG['trade_pair'] . ") FROM (SELECT " . $CONFIG['trade_pair'] . " FROM ticker ORDER BY id DESC LIMIT " . $sma_short_minutes . ") x")->fetch_array()[0]);
    $SmaLong = floatval($mysqli->query("SELECT AVG(x." . $CONFIG['trade_pair'] . ") FROM (SELECT " . $CONFIG['trade_pair'] . " FROM ticker ORDER BY id DESC LIMIT " . $sma_long_minutes . ") x")->fetch_array()[0]);
    $SmaDiff = 100 * ($SmaShort - $SmaLong) / (($SmaShort + $SmaLong) / 2);
    $last = floatval($mysqli->query("SELECT " . $CONFIG['trade_pair'] . " FROM ticker ORDER BY id DESC LIMIT 1")->fetch_array()[0]);
    $current = ($bought ? $ticker['sell'] : $ticker['buy']);
    $tradeAmount = $balance[$currency1] * $CONFIG['trade_amount'];

    // Print status
    echo date("d-m-Y H:i:s") . ($simulation ? ' ***SIMULATION***' : '') . PHP_EOL . PHP_EOL;
    echo 'Trade pair: ' . strtoupper($CONFIG['trade_pair']) . PHP_EOL;
    echo 'Trade amount: ' . $tradeAmount . ' ' . $currency1 . PHP_EOL;
    echo 'Account balance: ' . $balance[$currency1] . ' ' . $currency1 . ' ' . $balance[$currency2] . ' ' . $currency2 . ' (value: ' . $accountValue . ' ' . $currency2 . ')' . PHP_EOL . PHP_EOL;

    echo 'Current: ' . $current . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
    echo 'Last: ' . $last . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
    echo 'SMA: short ' . $SmaShort . ', long ' . $SmaLong . ', diff ' . $SmaDiff . '%' . PHP_EOL;
    if ($bought) {
       echo 'Bought: ' . $tradeAmount . ' ' . $currency1 . ' at ' . $buy_price . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
       echo 'Trailing-stop-margin: ' . $trailing_stop_margin . PHP_EOL;
       echo 'Stop-loss: ' . $stop_loss_percentage . '%' . PHP_EOL;
    } else {
       echo 'Buy threshold: ' . $buy_threshold . PHP_EOL;
    }

    //echo 'Average (' . $CONFIG['trade_average_range'] . ' min): ' . $average . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
    // echo 'Buying threshold: ' . $buyThreshold . ' ' . $currency2 . PHP_EOL;
    //echo 'Selling threshold: ' . $sellThreshold . ' ' . $currency2 . PHP_EOL;

    // There are two ways of stopping a loss
    // - Trailing stop, which stops us selling out if the price keeps climbing
    // - Stop-loss, which stops us losing out if the price goes down, instead of up

    // 1. This is an example of trailing stop. It works by having a margin which increases
    // like the price does, but never decreases. When the price crosses over this margin, we sell.
    if ($bought) {
        // Has the price gone up? If it has, add the difference between the
        // last price, and the current one to the trailing stop margin.
        if ($current > $last) {
            $trailing_stop_margin += $current - $last;
        }
        // Check if the price is less than the trailing stop margin. If it is, sell.
        if ($current < $trailing_stop_margin) {
            $bought = false;
            $buy_price = 0;
            $cost = ($tradeAmount * $current);
            $updateAccountBalance = 0;

            echo 'SELLING (stop-margin): ' . $tradeAmount . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . PHP_EOL;
            trade($CONFIG['trade_pair'], $tradeAmount, $current, 'sell');
        }
    }

    // 2. This is an example of stop-loss. It works by checking if the price has gone below a certain
    // percentage of the initial buy price. If it has, we sell.
    if ($bought) {
        // In this example we store the percentage as a constant, and the buy price.
        // You can alternatively calculate the stop loss price at buy point, and
        // store that.
        $stop_loss_price = $buy_price * ($stop_loss_percentage / 100);
        // Check if the price is less than the stop loss price. If it is, sell.
        if ($current < $stop_loss_price) {
            $bought = false;
            $buy_price = 0;
            $cost = ($tradeAmount * $current);
            $updateAccountBalance = 0;

            echo 'SELLING (stop-loss): ' . $tradeAmount . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . PHP_EOL;
            trade($CONFIG['trade_pair'], $tradeAmount, $current, 'sell');
        }
    }

    // Now, all you need is something to generate a buy signal. In this example we use
    // EMA crossover, but you can use just about anything, as long as you set the appropriate flags.
    if (!$bought) {
        if ($SmaDiff > $buy_threshold) {
            $bought = true;
            $buy_price = $current;
            $cost = ($tradeAmount * $current);
            $updateAccountBalance = 0;
            $trailing_stop_margin = $buy_price * ($stop_loss_percentage / 100);

            echo 'BUYING: ' . $tradeAmount . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . PHP_EOL;
            trade($CONFIG['trade_pair'], $tradeAmount, $current, 'buy');
        }
    }

    // Wait for a while
    echo str_repeat('-', 30) . ' waiting ' . $CONFIG['trade_wait'] . ' sec ' . str_repeat('-', 30) . PHP_EOL;
    sleep($CONFIG['trade_wait']);
}


function EMA($days) {
    // http://stockcharts.com/school/doku.php?id=chart_school:technical_indicators:moving_averages
    // http://www.iexplain.org/ema-how-to-calculate/
    // EMA = Price(t) * k + EMA(y) * (1 – k)
    // t = today, y = yesterday, N = number of days in EMA, k = 2/(N+1)

    $k = 2 / ($days+1);
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
        return false;
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
        //print_r($ticker);
        return $ticker['ticker'];
    } catch(BTCeAPIException $e) {
        echo $e->getMessage();
        return false;
    }
}




function trade($trade_pair, $currency1, $currency2, $buyOrSell = 'sell') {
    global $BTCeAPI, $lastAction, $minimumProfitableSell, $simulation;

    $currency1 = number_format($currency1, 6);
    $currency2 = number_format($currency2, 6);

    try {
        //$BTCeAPI->makeOrder($amount, $pair, $direction, $price);
        //echo "BTCeAPI->makeOrder(".$currency1.", ".$trade_pair.",". $buyOrSell. ",". $currency2. ");" . PHP_EOL;
        if ($simulation !== true) {
           $BTCeAPI->makeOrder($currency1, $trade_pair, $buyOrSell, $currency2);
        }
        $lastAction = $buyOrSell;
        $minimumProfitableSell = $currency2;
    } catch(BTCeAPIInvalidParameterException $e) {
        echo $e->getMessage();
    } catch(BTCeAPIException $e) {
        echo $e->getMessage();
    }
}
