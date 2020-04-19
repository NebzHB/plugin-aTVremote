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
if [ $(lsb_release -c | grep 'jessie' | wc -l) -eq 1 ]; then
    echo "ATTENTION CE PLUGIN NE FONCTIONNE PAS SOUS JESSIE, MERCI DE METTRE A JOUR VOTRE DISTRIBUTION !!!"
	echo "********************************************************"
	echo "*             Installation terminée                    *"
	echo "********************************************************"
    exit 1;
fi
sudo apt-get update
echo 10 > ${PROGRESS_FILE}
echo "--10%"
echo "Installation des dépendances apt"
cd ..
cd ..
cd plugins/aTVremote/resources/dependances_python/
sudo apt-get -y install python3 python3-pip python3-setuptools build-essential libssl-dev libffi-dev python3-dev
echo 20 > ${PROGRESS_FILE}
echo "--20%"
sudo curl -O https://www.python.org/ftp/python/3.6.9/Python-3.6.9.tar.xz
sudo tar -xf Python-3.6.9.tar.xz
cd Python-3.6.9/
echo 30 > ${PROGRESS_FILE}
echo "--30%"
./configure --enable-optimizations
make -j 2
sudo make install
#sudo pip3 install aiohttp==3.0.1
#echo 60 > ${PROGRESS_FILE}
echo 80 > ${PROGRESS_FILE}
echo "--80%"
sudo pip3 install -I wheel
sudo pip3 install -I git+https://github.com/NebzHB/pyatv@release_0_5_x
sudo pip3 install --upgrade pip
echo 100 > /${PROGRESS_FILE}
echo "--100%"
echo "********************************************************"
echo "*             Installation terminée                    *"
echo "********************************************************"
rm ${PROGRESS_FILE}
