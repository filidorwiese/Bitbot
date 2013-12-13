<?php

$CONFIG = array(
	'btce_api_key' => 'HFEYDF5G-587HLVB8-PQYXFVTX-A1A33GN7-NNBFR3DG',
	'btce_api_secret' => 'd0c0099321df68f7ed4e22faa7a5fbfdc62a66127e856ead9d58cf62c4f46379',

        'mysql_host' => 'localhost',
        'mysql_user' => 'bitbot',
        'mysql_password' => 'xdC8AzxbtLd42YfV',
        'mysql_database' => 'bitbot',

	'trade_pair' => 'ltc_usd',
	'trade_amount' => 0.2, // 0.3
	'trade_threshold' => 0.02, // 0.02
	'trade_wait' => 8, // Seconds
        'trade_average_range' => 60, // Minutes
        'trade_trailing_stop_threshold' => 0,
        'trade_stop_loss_percentage' => 95,
);
