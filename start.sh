#!/bin/sh
killall -q Trader.php
nohup ./Trader.php > /var/log/bitbot.log &
