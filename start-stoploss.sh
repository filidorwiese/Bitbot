#!/bin/sh
killall -q StopLoss.php
nohup ./StopLoss.php real > /var/log/bitbot.log &
