#!/bin/bash
######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
TIMED=1
. ${BASEDIR}/dependance.lib
##################################################################
wget https://raw.githubusercontent.com/NebzHB/nodejs_install/main/install_nodejs.sh -O $BASEDIR/install_nodejs.sh &>/dev/null

installVer='18' 	#NodeJS major version to be installed

pre
step 0 "Vérification des droits"
DIRECTORY="/var/www"
if [ ! -d "$DIRECTORY" ]; then
	silent sudo mkdir $DIRECTORY
fi
silent sudo chown -R www-data $(realpath $BASEDIR/..)

#jessie as libstdc++ > 4.9 needed for nodejs 12+
lsb_release -c | grep jessie
if [ $? -eq 0 ]
then
    echo "$HR"
    echo "== KO == Erreur d'Installation"
    echo "$HR"
    echo "== ATTENTION Debian 8 Jessie n'est officiellement plus supportée depuis le 30 juin 2020, merci de mettre à jour votre distribution !!!"
    exit 1
fi

#stretch is not supported because of old python
lsb_release -c | grep stretch
if [ $? -eq 0 ]
then
    echo "$HR"
    echo "== KO == Erreur d'Installation"
    echo "$HR"
    echo "== ATTENTION Debian 9 Stretch n'est pas supporté car la version de python est trop vieille, merci de mettre à jour votre distribution !!!"
    exit 1
fi

#stretch is not supported because of old python
lsb_release -c | grep buster
if [ $? -eq 0 ]
then
    echo "$HR"
    echo "== KO == Erreur d'Installation"
    echo "$HR"
    echo "== ATTENTION Debian 10 Buster n'est pas supporté car la version de python est trop vieille, merci de mettre à jour votre distribution !!!"
    exit 1
fi

step 5 "Mise à jour APT et installation des packages nécessaires"
tryOrStop sudo apt-get update
tryOrStop apt-get install -y python3 python3-pip python3-dev python3-venv

#install nodejs, steps 10->50
. ${BASEDIR}/install_nodejs.sh ${installVer}

step 60 "Nettoyage anciens modules"
cd ${BASEDIR};
#remove old local modules
silent sudo rm -fR node_modules
silent sudo rm -f package-lock.json

step 70 "Installation des librairies du démon, veuillez patienter svp"
silent sudo mkdir node_modules 
silent sudo chown -R www-data:www-data . 
tryOrStop sudo npm install --no-fund --no-package-lock --no-audit
silent sudo chown -R www-data:www-data . 

step 80 "Installation librairie atvremote"
VENV_DIR=$BASEDIR/atvremote
tryOrStop python3 -m venv $VENV_DIR
tryOrStop $VENV_DIR/bin/python3 -m pip install --no-cache-dir --upgrade pip wheel
tryOrStop $VENV_DIR/bin/python3 -m pip install --no-cache-dir -I git+https://github.com/NebzHB/pyatv@master

step 90 "Résumé des packages installés"
$VENV_DIR/bin/python3 -m pip freeze

post
