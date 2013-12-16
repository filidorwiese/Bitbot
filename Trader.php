#!/usr/bin/php
<?php

chdir(dirname(__FILE__));

$profile = (isset($argv[1]) ? $argv[1] : false);
$profileFile = 'profiles/' . $profile . '.profile.php';
if (!is_file($profileFile)) { die("Please specify trade profile" . PHP_EOL); }
require_once($profileFile);

// Connect to BTC-e
require_once('./btce-api/btce-api.php');
$BTCeAPI = new BTCeAPI($CONFIG['btce_api_key'], $CONFIG['btce_api_secret'], '/tmp/nonce-' . $profile);

// Connect to MySQl
$mysqli = new mysqli($CONFIG['mysql_host'], $CONFIG['mysql_user'], $CONFIG['mysql_password'], $CONFIG['mysql_database']);
if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error . PHP_EOL;
    exit;
}

// Check trade configuration
if (!(isset($TRADE['trade_pair'])
   && isset($TRADE['trade_wait'])
   && isset($TRADE['trade_stop_loss'])
   && isset($TRADE['trade_sma_short'])
   && isset($TRADE['trade_sma_long'])
   && isset($TRADE['trade_threshold']))) {
      die("Not all configuration options are present");
}

// Enter trade loop
$simulation = (isset($argv[2]) && $argv[2] === 'real' ? false : true);
$tradeLog = './logs/' . $profile . '.log';
$bought = 0;
$buy_price = 0;
$trailing_stop_margin = 0;

while (true) {
    // Get ticker status for trading pair
    $ticker = getTicker($TRADE['trade_pair']);
    if ($ticker === false) { sleep($TRADE['trade_wait']); continue; }

    // Get balance for trading pair from db
    list($currency1, $currency2) = explode('_', $TRADE['trade_pair']);
    $balance = $mysqli->query("SELECT * FROM balance LIMIT 1")->fetch_assoc();
    $accountValue = ($balance[$currency1] * $ticker['sell']) + $balance[$currency2];

    // Calculate average/threshold/etc
    $SmaShort = floatval($mysqli->query("SELECT AVG(x." . $TRADE['trade_pair'] . ") FROM (SELECT " . $TRADE['trade_pair'] . " FROM ticker ORDER BY id DESC LIMIT " . $TRADE['trade_sma_short'] . ") x")->fetch_array()[0]);
    $SmaLong = floatval($mysqli->query("SELECT AVG(x." . $TRADE['trade_pair'] . ") FROM (SELECT " . $TRADE['trade_pair'] . " FROM ticker ORDER BY id DESC LIMIT " . $TRADE['trade_sma_long'] . ") x")->fetch_array()[0]);
    $SmaDiff = 100 * ($SmaShort - $SmaLong) / (($SmaShort + $SmaLong) / 2);
    $last = floatval($mysqli->query("SELECT " . $TRADE['trade_pair'] . " FROM ticker ORDER BY id DESC LIMIT 1")->fetch_array()[0]);
    $tradeAmount = number_format($balance[$currency2] * $TRADE['trade_amount'], 6);
    $changePercentage = ((($ticker['buy'] - $last) / $ticker['buy']) * 100);

    // Print status
    echo date("d-m-Y H:i:s") . ($simulation ? ' ***SIMULATION***' : '') . PHP_EOL . PHP_EOL;
    echo 'Profile: ' . $profile . PHP_EOL;
    echo 'Trade pair: ' . strtoupper($TRADE['trade_pair']) . PHP_EOL;
    echo 'Trade amount: ' . $tradeAmount . ' ' . $currency2 . PHP_EOL;
    echo 'Account balance: ' . $balance[$currency1] . ' ' . $currency1 . ' ' . $balance[$currency2] . ' ' . $currency2 . PHP_EOL . PHP_EOL;// . ' (value: ' . $accountValue . ' ' . $currency2 . ')' . PHP_EOL . PHP_EOL;

    echo 'Current: ' . $ticker['buy'] . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
    echo 'Last: ' . $last . ' ' . $currency2 . ' per ' . $currency1 . ' ' . number_format($changePercentage, 6) . '%' . PHP_EOL;
    echo 'SMA: short ' . number_format($SmaShort, 6) . ', long ' . number_format($SmaLong, 6) . ', diff ' . number_format($SmaDiff, 6) . '%' . PHP_EOL;

    if ($bought > 0) {
       echo 'Bought: ' . $bought . ' ' . $currency1 . ' at ' . $buy_price . ' ' . $currency2 . ' per ' . $currency1 . PHP_EOL;
       echo 'Trailing-stop-margin: ' . $trailing_stop_margin . PHP_EOL;
       echo 'Stop-loss: ' . $TRADE['trade_stop_loss'] . '%' . PHP_EOL;
    } else {
       echo 'Buy threshold: ' . $TRADE['trade_threshold'] . PHP_EOL . PHP_EOL . PHP_EOL;
    }

    // Buy shares on SMA cross-over
    if (!$bought) {
        if ($SmaDiff > $TRADE['trade_threshold']) {
            if ($tradeAmount < $balance[$currency2]) {
                $buy_price = $ticker['buy'];
                $bought = number_format(($tradeAmount / $ticker['buy']), 6);
		$cost = number_format($ticker['buy'] * $bought, 6);
                $trailing_stop_margin = $buy_price * ($TRADE['trade_stop_loss'] / 100);

                trade($TRADE['trade_pair'], $bought, $ticker['buy'], 'buy');
                echo 'BUYING: ' . $bought . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . ' at ' . $ticker['buy'] . PHP_EOL;
                TradeLog($tradeLog, 'Buying: ' . $bought . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . ' at ' . $ticker['buy']);
	    } else {
                echo 'NOT ENOUGH ' . $currency2 . PHP_EOL;
            }
        }
    }

    // Sell on trailing stop-margin
    if ($bought > 0) {
        if ($ticker['sell'] > $last) {
            $trailing_stop_margin = max($trailing_stop_margin, $ticker['sell'] * ($TRADE['trade_stop_loss'] / 100));
            //$trailing_stop_margin += $ticker['sell'] - $last;
        }

        // Check if the price is less than the trailing stop margin. If it is, sell.
        if ($ticker['sell'] < $trailing_stop_margin) {
            $cost = number_format($bought * $ticker['sell'], 6);

            trade($TRADE['trade_pair'], $bought, $ticker['sell'], 'sell');

            echo 'SELLING (stop-margin): ' . $bought . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . ' at ' . $ticker['sell'] . PHP_EOL;
	    TradeLog($tradeLog, 'Selling: ' . $bought . ' ' . $currency1 . ' for ' . $cost . ' ' . $currency2 . ' at ' . $ticker['sell']);

            $bought = 0;
            $buy_price = 0;
        }
    }

    // Wait for a while
    echo str_repeat('-', 30) . ' waiting ' . $TRADE['trade_wait'] . ' sec ' . str_repeat('-', 30) . PHP_EOL;
    sleep($TRADE['trade_wait']);
}


function TradeLog($file, $line) {
global $simulation;
     if ($simulation) { return; }
     file_put_contents($file, '[' . date("d-m-Y H:i:s") . '] ' .$line . PHP_EOL, FILE_APPEND);
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

    $currency1 = (float)number_format($currency1, 6);
    $currency2 = (float)number_format($currency2, 6);

    try {
        //$BTCeAPI->makeOrder($amount, $pair, $direction, $price);
        echo "BTCeAPI->makeOrder(".$currency1.", ".$trade_pair.", ". $buyOrSell. ", ". $currency2. ");" . PHP_EOL;
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
