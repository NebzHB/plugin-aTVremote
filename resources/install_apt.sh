#!/bin/bash
PROGRESS_FILE=/tmp/dependancy_aTVremote_in_progress
if [ ! -z $1 ]; then
	PROGRESS_FILE=$1
fi
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "--0%"
echo "********************************************************"
echo "*             Installation des dépendances             *"
echo "********************************************************"
sudo apt-get update
echo 10 > ${PROGRESS_FILE}
echo "--10%"
echo "Installation des dépendances apt"
sudo apt-get -y install python3 python3-pip python3-setuptools build-essential libssl-dev libffi-dev python3-dev
echo 50 > ${PROGRESS_FILE}
echo "--50%"
#sudo pip3 install aiohttp==3.0.1
#echo 60 > ${PROGRESS_FILE}
#echo "--60%"
#sudo pip3 install pyatv
sudo pip3 install git+https://github.com/postlund/pyatv@fix_bugs
echo 100 > /${PROGRESS_FILE}
echo "--100%"
echo "********************************************************"
echo "*             Installation terminée                    *"
echo "********************************************************"
rm ${PROGRESS_FILE}
