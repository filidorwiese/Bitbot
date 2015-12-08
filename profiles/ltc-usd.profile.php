<?php

$CONFIG = array(
	'btce_api_key' => '',
	'btce_api_secret' => '',

        'mysql_host' => 'localhost',
        'mysql_user' => 'bitbot',
        'mysql_password' => '',
        'mysql_database' => 'bitbot',
);

$TRADE = array(
	'trade_pair' => 'ltc_usd',
	'trade_amount' => 0.5,
	'trade_wait' => 8, 		// seconds
	'trade_threshold' => 0.35,
	'trade_stop_loss' => 98, 	// percentage
	'trade_sma_short' => 10,	// minutes
	'trade_sma_long' => 120,	// minutes
);
