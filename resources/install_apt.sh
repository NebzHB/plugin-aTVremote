#!/bin/bash
######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
TIMED=1
. ${BASEDIR}/dependance.lib
##################################################################
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/install_nodejs.sh -O $BASEDIR/install_nodejs.sh &>/dev/null

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
	echo 1 > $TMPFOLDER/hasError.$$
	echo -e "$HR" >> $TMPFOLDER/errorLog.$$ 
	echo -e "== ATTENTION Debian 8 Jessie n'est officiellement plus supportée depuis le 30 juin 2020, merci de mettre à jour votre distribution !!!" >> $TMPFOLDER/errorLog.$$ 
	post
	exit 1
fi

#stretch is not supported because of old python
lsb_release -c | grep stretch
if [ $? -eq 0 ]
then
	echo 1 > $TMPFOLDER/hasError.$$
	echo -e "$HR" >> $TMPFOLDER/errorLog.$$ 
	echo -e "== ATTENTION Debian 9 Stretch n'est officiellement plus supportée depuis le 30 juin 2022, merci de mettre à jour votre distribution !!!" >> $TMPFOLDER/errorLog.$$ 
	post
	exit 1
fi

#stretch is not supported because of old python
lsb_release -c | grep buster
if [ $? -eq 0 ]
then
	if [ ! -f /media/boot/multiboot/meson64_odroidc2.dtb.linux ]; then
		today=$(date +%Y%m%d)
		if [[ "$today" > "20240630" ]]; then
			echo 1 > $TMPFOLDER/hasError.$$
			if [ "$LANG_DEP" = "fr" ]; then
				echo -e ":fg-danger:$HR:/fg:" >> $TMPFOLDER/errorLog.$$ 
				echo -e ":fg-danger:== ATTENTION Debian 10 Buster n'est officiellement plus supportée depuis le 30 juin 2024, merci de mettre à jour votre distribution !!!:/fg:" >> $TMPFOLDER/errorLog.$$ 
				echo -e ":fg-danger:== Les dépendances sont bloquées afin d'éviter tout problème, soit $PLUGIN fonctionne et donc on y touche plus tant qu'il tourne, soit il ne fonctionne plus et donc il faut mettre à jour votre distribution.:/fg:" >> $TMPFOLDER/errorLog.$$ 
				echo -e ":fg-danger:$HR:/fg:" >> $TMPFOLDER/errorLog.$$ 
			else
				echo -e ":fg-danger:$HR:/fg:" >> $TMPFOLDER/errorLog.$$ 
				echo -e ":fg-danger:== WARNING Debian 10 Buster is not supported anymore since June 30, 2024. Please update your distribution!!!:/fg:" >> $TMPFOLDER/errorLog.$$ 
				echo -e ":fg-danger:== Dependencies are blocked to avoid any issues. Either $PLUGIN works and so we don't touch the dependencies as long as it works, or it doesn't work anymore and you have to update your distribution.:/fg:" >> $TMPFOLDER/errorLog.$$ 
				echo -e ":fg-danger:$HR:/fg:" >> $TMPFOLDER/errorLog.$$ 
			fi
			post
			exit 1
		fi
	else
		if [ "$LANG_DEP" = "fr" ]; then
			echo ":fg-warning:$HR:/fg:"
			echo ":fg-warning:== WARNING == A VERIFIER AU PLUS VITE:/fg:"
			echo
			echo ":fg-warning:$HR:/fg:"
			echo ":fg-warning:== ATTENTION Debian 10 Buster n'est officiellement plus supportée depuis le 30 juin 2024, cependant l'image Debian 11 de la Smart est en cours de finalisation par Jeedom.:/fg:"
			echo ":fg-warning:== Les dépendances vont quand même se lancer (mais aucun support ne sera fait si celles-ci ne fonctionnent pas !), surveillez les nouvelles de Jeedom afin de mettre à jour en Debian 11 au plus vite quand ils auront sorti leur nouvelle image.:/fg:"
		else
			echo ":fg-warning:$HR:/fg:"
			echo ":fg-warning:== WARNING == TO CHECK SOON:/fg:"
			echo
			echo ":fg-warning:$HR:/fg:"
			echo ":fg-warning:== WARNING Debian 10 Buster is not supported anymore since June 30, 2024. Nevertheless, the Debian 11 image for the Smart box is still under development by Jeedom.:/fg:"
			echo ":fg-warning:== Dependancies will continue (but no support will be done if it fails). Watch for Jeedom news to update to Debian 11 as soon as they release the new image.:/fg:"
		fi
	fi
fi

step 5 "Mise à jour APT et installation des packages nécessaires"
tryOrStop sudo apt-get update
tryOrStop apt-get install -y python3 python3-pip python3-dev python3-venv

#install nodejs, steps 10->50
. ${BASEDIR}/install_nodejs.sh --firstSubStep 10 --lastSubStep 50

step 60 "Nettoyage anciens modules"
cd ${BASEDIR};
#remove old local modules
silent sudo rm -fR node_modules
silent sudo rm -f package-lock.json

step 70 "Installation des librairies du démon, veuillez patienter svp"
silent sudo mkdir node_modules 
silent sudo chown -R www-data:www-data . 
tryOrStop sudo npm install --no-fund --no-package-lock --no-audit
#silent wget https://raw.githubusercontent.com/NebzHB/nodejsToJeedom/main/jeedom.js -O $BASEDIR/utils/jeedom.js
silent sudo chown -R www-data:www-data . 

step 80 "Installation librairie atvremote"
VENV_DIR=$BASEDIR/atvremote
tryOrStop python3 -m venv $VENV_DIR
tryOrStop $VENV_DIR/bin/python3 -m pip install --no-cache-dir --upgrade pip wheel
tryOrStop $VENV_DIR/bin/python3 -m pip install --no-cache-dir -I git+https://github.com/postlund/pyatv@v0.15.1

step 90 "Résumé des packages installés"
$VENV_DIR/bin/python3 -m pip freeze

post
