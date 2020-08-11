#!/bin/bash
######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
. ${BASEDIR}/dependance.lib
##################################################################
TIMED=1

installVer='12' 	#NodeJS major version to be installed
minVer='12'	#min NodeJS major version to be accepted
maxVer='12'

pre
step 0 "Vérification des droits"
DIRECTORY="/var/www"
if [ ! -d "$DIRECTORY" ]; then
	silent sudo mkdir $DIRECTORY
fi
silent sudo chown -R www-data $DIRECTORY

step 10 "Prérequis"

if [ -f /etc/apt/sources.list.d/deb-multimedia.list* ]; then
  echo "Vérification si la source deb-multimedia existe (bug lors du apt-get update si c'est le cas)"
  echo "deb-multimedia existe !"
  if [ -f /etc/apt/sources.list.d/deb-multimedia.list.disabledByaTVremote ]; then
    echo "mais on l'a déjà désactivé..."
  else
    if [ -f /etc/apt/sources.list.d/deb-multimedia.list ]; then
      echo "Désactivation de la source deb-multimedia !"
      silent sudo mv /etc/apt/sources.list.d/deb-multimedia.list /etc/apt/sources.list.d/deb-multimedia.list.disabledByaTVremote
    else
      if [ -f /etc/apt/sources.list.d/deb-multimedia.list.disabled ]; then
        echo "mais il est déjà désactivé..."
      else
        echo "mais n'est ni 'disabled' ou 'disabledByaTVremote'... il sera normalement ignoré donc ca devrait passer..."
      fi
    fi
  fi
fi

toReAddRepo=0
if [ -f /media/boot/multiboot/meson64_odroidc2.dtb.linux ]; then
    hasRepo=$(grep "repo.jeedom.com" /etc/apt/sources.list | wc -l)
    if [ "$hasRepo" -ne "0" ]; then
      echo "Désactivation de la source repo.jeedom.com !"
      toReAddRepo=1
      sudo apt-add-repository -r "deb http://repo.jeedom.com/odroid/ stable main"
    fi
fi

#prioritize nodesource nodejs
sudo bash -c "cat >> /etc/apt/preferences.d/nodesource" << EOL
Package: nodejs
Pin: origin deb.nodesource.com
Pin-Priority: 600
EOL

step 20 "Mise à jour APT et installation des packages nécessaires"
try sudo apt-get update
try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y lsb-release
if [ $(lsb_release -c | grep 'jessie' | wc -l) -eq 1 ]; then
	echo "$HR"
	echo "== KO == Erreur d'Installation"
	echo "$HR"
	echo "== ATTENTION CE PLUGIN NE FONCTIONNE PAS SOUS JESSIE, MERCI DE METTRE A JOUR VOTRE DISTRIBUTION !!!"
    exit 1;
fi
try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y python3-setuptools build-essential python3-dev libssl-dev libffi-dev

step 30 "Vérification de la version de NodeJS installée"
silent type nodejs
if [ $? -eq 0 ]; then actual=`nodejs -v`; fi
echo "Version actuelle : ${actual}"
arch=`arch`;

#jeedom mini and rpi 1 2, NodeJS 12 does not support arm6l
if [[ $arch == "armv6l" ]]
then
  echo "$HR"
  echo "== KO == Erreur d'Installation"
  echo "$HR"
  echo "== ATTENTION Vous possédez une Jeedom mini ou Raspberry zero/1/2 (arm6l) et NodeJS 12 n'y est pas supporté, merci d'utiliser du matériel récent !!!"
  exit 1
fi

bits=$(getconf LONG_BIT)
vers=$(lsb_release -c | grep stretch | wc -l)
if { [ "$arch" = "i386" ] || [ "$arch" = "i686" ]; } && [ "$bits" -eq "32" ] && [ "$vers" -eq "1" ]
then 
  echo "$HR"
  echo "== KO == Erreur d'Installation"
  echo "$HR"
  echo "== ATTENTION Votre système est x86 en 32bits et NodeJS 12 n'y est pas supporté, merci de passer en 64bits !!!"
  exit 1 
fi

testVer=$(php -r "echo version_compare('${actual}','v${minVer}','>=');")
if [[ $testVer == "1" ]]
then
  echo "Ok, version suffisante";
  new=$actual
else
  step 40 "Installation de NodeJS $installVer"
  echo "KO, Version obsolète à upgrader";
  echo "Suppression du Nodejs existant et installation du paquet recommandé"
  #if npm exists
  silent type npm
  if [ $? -eq 0 ]; then
    cd `npm root -g`;
    silent sudo npm rebuild
    npmPrefix=`npm prefix -g`
  else
    npmPrefix="/usr"
  fi
  
  silent sudo DEBIAN_FRONTEND=noninteractive apt-get -y --purge autoremove npm
  silent sudo DEBIAN_FRONTEND=noninteractive apt-get -y --purge autoremove nodejs
  
  if [[ $arch == "armv6l" ]]
  then
    echo "Raspberry 1, 2 ou zéro détecté, utilisation du paquet v${installVer} pour ${arch}"
    try wget -nd -nH -nc -np -e robots=off -r -l1 --no-parent -A"node-*-linux-${arch}.tar.gz" https://nodejs.org/download/release/latest-v${installVer}.x/
    try tar -xvf node-*-linux-${arch}.tar.gz
    cd node-*-linux-${arch}
    try sudo cp -R * /usr/local/
    cd ..
    silent rm -fR node-*-linux-${arch}* 
    silent ln -s /usr/local/bin/node /usr/bin/node
    silent ln -s /usr/local/bin/node /usr/bin/nodejs
    #upgrade to recent npm
    try sudo npm install -g npm
  else
    echo "Utilisation du dépot officiel"
    curl -sL https://deb.nodesource.com/setup_${installVer}.x | try sudo -E bash -
    try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs  
  fi
  
  silent npm config set prefix ${npmPrefix}

  new=`nodejs -v`;
  echo "Version après install : ${new}"
  testVerAfter=$(php -r "echo version_compare('${new}','v${minVer}','>=');")
  if [[ $testVerAfter != "1" ]]
  then
    echo "Version non suffisante, relancez les dépendances"
  fi
fi

silent type npm
if [ $? -ne 0 ]; then
  step 45 "Installation de npm car non présent"
  try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y npm  
  try sudo npm install -g npm
fi

step 50 "Nettoyage anciens modules"
cd ${BASEDIR};
#remove old local modules
silent sudo rm -rf node_modules
silent sudo rm -f package-lock.json

step 60 "Installation des librairies du démon, veuillez patienter svp"
silent sudo mkdir node_modules 
silent sudo chown -R www-data:www-data . 
try sudo npm install --no-fund --no-package-lock --no-audit
silent sudo chown -R www-data:www-data . 


lsb_release -c | grep stretch
if [ $? -eq 0 ]
then
  silent python3.7 --version
  if [ $? -ne 0 ]; then
    step 70 "Installation de python 3.7"
	try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y zlib1g-dev libncurses5-dev libgdbm-dev libnss3-dev libreadline-dev curl
    try sudo curl -O https://www.python.org/ftp/python/3.7.3/Python-3.7.3.tar.xz
    silent sudo tar -xf Python-3.7.3.tar.xz
    #Clean tar file
    silent sudo rm -fR Python-3.7.3.tar.xz
    cd Python-3.7.3/
    #sudo ./configure --enable-optimizations #too slow
    try sudo ./configure --prefix=/usr
    try sudo make -j 2
    try sudo make altinstall
    cd ..
    #Clean Python folder
    silent sudo rm -fR Python-3.7.3/
  fi
fi

step 80 "Installation librairie atvremote"
silent sudo rm -fR ${BASEDIR}/atvremote
try sudo pip3 install virtualenv
try sudo virtualenv -p `which python3.7` ${BASEDIR}/atvremote/
source <(sudo cat ${BASEDIR}/atvremote/bin/activate)

try sudo `which pip3` install -I wheel
#try sudo `which pip3` install -I git+https://github.com/NebzHB/pyatv@release_0_5_x
try sudo `which pip3` install -I git+https://github.com/NebzHB/pyatv@master
deactivate

try sudo pip3 install --upgrade pip
try sudo ${BASEDIR}/atvremote/bin/python -m pip install --upgrade pip


step 90 "Nettoyage"
silent sudo rm -f /etc/apt/preferences.d/nodesource
if [ -f /etc/apt/sources.list.d/deb-multimedia.list.disabledByaTVremote ]; then
  echo "Réactivation de la source deb-multimedia qu'on avait désactivé !"
  silent sudo mv /etc/apt/sources.list.d/deb-multimedia.list.disabledByaTVremote /etc/apt/sources.list.d/deb-multimedia.list
fi
if [ "$toReAddRepo" -ne "0" ]; then
  echo "Réactivation de la source repo.jeedom.com qu'on avait désactivé !"
  toReAddRepo=0
  silent sudo wget --quiet -O - http://repo.jeedom.com/odroid/conf/jeedom.gpg.key | sudo apt-key add -
  silent sudo apt-add-repository "deb http://repo.jeedom.com/odroid/ stable main"
fi

post
