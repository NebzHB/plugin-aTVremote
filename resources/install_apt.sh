#!/bin/bash
######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget -4 https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib --no-cache -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
TIMED=1
. ${BASEDIR}/dependance.lib
##################################################################
wget -4 https://raw.githubusercontent.com/NebzHB/dependance.lib/master/install_nodejs.sh --no-cache -O $BASEDIR/install_nodejs.sh &>/dev/null
wget -4 https://raw.githubusercontent.com/NebzHB/dependance.lib/master/pyenv.lib --no-cache -O ${BASE_DIR}/pyenv.lib &>/dev/null
. ${BASE_DIR}/pyenv.lib
##################################################################

pre
step 0 "Vérification des droits"
DIRECTORY="/var/www"
if [ ! -d "$DIRECTORY" ]; then
	silent sudo mkdir $DIRECTORY
fi
silent sudo chown -R www-data $(realpath $BASEDIR/..)

step 5 "Mise à jour APT"
tryOrStop sudo apt-get update

#install nodejs, steps 10->50
. ${BASEDIR}/install_nodejs.sh --firstSubStep 10 --lastSubStep 50

step 55 "Nettoyage anciens modules"
cd ${BASEDIR};
#remove old local modules
silent sudo rm -fR node_modules
silent sudo rm -f package-lock.json

step 60 "Installation des librairies du démon, veuillez patienter svp"
silent sudo mkdir node_modules 
silent sudo chown -R www-data:www-data . 
tryOrStop sudo npm install --no-fund --no-package-lock --no-audit
#silent wget https://raw.githubusercontent.com/NebzHB/nodejsToJeedom/main/jeedom.js -O $BASEDIR/utils/jeedom.js
silent sudo chown -R www-data:www-data . 

VENV_DIR=$BASEDIR/atvremote
firstSubStep=70
lastSubStep=95
autoSetupVenv

#tryOrStop python3 -m venv $VENV_DIR
#tryOrStop $VENV_DIR/bin/python3 -m pip install --no-cache-dir --upgrade pip wheel
tryOrStop $VENV_DIR/bin/python3 -m pip install --upgrade --upgrade-strategy eager --no-cache-dir -I git+https://github.com/NebzHB/pyatv@v0.15.1

step 98 "Résumé des packages installés"
$VENV_DIR/bin/python3 -m pip freeze

post
