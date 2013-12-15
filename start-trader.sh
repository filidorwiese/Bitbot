#!/bin/sh
#killall -q "Trader.php ltc-usd-thight"

if [ -z "$1" ]; then
	echo No profile specified
	exit
fi
sudo -u bitbot nohup ./Trader.php $1 real > /var/log/bitbot/$1.log &
