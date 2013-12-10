#!/bin/sh
killall -q Trader.php
nohup ./Trader.php real > /var/log/bitbot.log &
