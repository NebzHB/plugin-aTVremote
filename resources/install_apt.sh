#!/bin/bash
######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
. ${BASEDIR}/dependance.lib
##################################################################
TIMED=1
wget https://raw.githubusercontent.com/NebzHB/nodejs_install/main/install_nodejs.sh -O $BASEDIR/install_nodejs.sh &>/dev/null

installVer='14' 	#NodeJS major version to be installed

pre
step 0 "Vérification des droits"
DIRECTORY="/var/www"
if [ ! -d "$DIRECTORY" ]; then
	silent sudo mkdir $DIRECTORY
fi
silent sudo chown -R www-data $DIRECTORY

step 5 "Mise à jour APT et installation des packages nécessaires"
try sudo apt-get update

#install nodejs, steps 10->50
. ${BASEDIR}/install_nodejs.sh ${installVer}

step 60 "Nettoyage anciens modules"
cd ${BASEDIR};
#remove old local modules
silent sudo rm -rf node_modules
silent sudo rm -f package-lock.json

step 70 "Installation des librairies du démon, veuillez patienter svp"
silent sudo mkdir node_modules 
silent sudo chown -R www-data:www-data . 
try sudo npm install --no-fund --no-package-lock --no-audit
silent sudo chown -R www-data:www-data . 

lsb_release -c | grep stretch
if [ $? -eq 0 ]
then
  silent python3.7 --version
  if [ $? -ne 0 ]; then
    step 80 "Installation de python 3.7"
    try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y zlib1g-dev libncurses5-dev libgdbm-dev libnss3-dev libreadline-dev curl
    try sudo curl -O https://www.python.org/ftp/python/3.7.9/Python-3.7.9.tar.xz
    silent sudo tar -xf Python-3.7.9.tar.xz
    #Clean tar file
    silent sudo rm -fR Python-3.7.9.tar.xz
    cd Python-3.7.9/
    #sudo ./configure --enable-optimizations #too slow
    try sudo ./configure --prefix=/usr
    try sudo make -j 2
    try sudo make altinstall
    cd ..
    #Clean Python folder
    silent sudo rm -fR Python-3.7.9/
  fi
fi

step 90 "Installation librairie atvremote"
silent sudo rm -fR ${BASEDIR}/atvremote
try sudo pip3 install virtualenv
try sudo virtualenv -p `which python3.7` ${BASEDIR}/atvremote/
source <(sudo cat ${BASEDIR}/atvremote/bin/activate)

try sudo `which pip3` install -I setuptools_rust
try sudo `which pip3` install -I wheel
#try sudo `which pip3` install -I git+https://github.com/NebzHB/pyatv@release_0_5_x
try sudo `which pip3` install -I git+https://github.com/NebzHB/pyatv@master
deactivate

try sudo pip3 install --upgrade pip
try sudo ${BASEDIR}/atvremote/bin/python -m pip install --upgrade pip

post
