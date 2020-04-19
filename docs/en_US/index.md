Ce plugin permet de monitorer et exécuter quelques actions sur vos AppleTV.

Veuillez Noter 
==============
- Compatible uniquement avec **Debian Stretch au minimum** !! (Pas compatible Debian Jessie, Python 3.5 minimum)
- Ce plugin n'est pas terminé, il s'agit plus d'une Preuve de Concept que je vous met à dispo. Les temps de réaction sont lents car une nouvelle session est ouverte à chaque commande vers l'AppleTV.
- Ce plugin utilise le protocole DAAP pour communiquer avec votre AppleTV.
- Il n'est pas possible avec ce protocole de :
  - Mettre en veille votre AppleTV
  - Effectuer des pressions longue sur les boutons (je vous ai vu venir...)
  - Modifier le volume (c'est votre TV qui s'en charge)
  - Effectuer le bouton "Micro" (Siri) de la télécommande :'(
  - Savoir si votre AppleTV est en Veille ou pas (Elle continue aussi à répondre aux pings dans les deux cas)
- Si votre AppleTV est en veille et vous cliquer sur une commande, elle sortira de veille.
- Testé sur AppleTV 4 et il fonctionnerait sur une 4K aussi. Pour l'AppleTV 3 c'est pas sur...
- **Le partage à Domicile DOIT être activé dans Réglages > Comptes > Partage à domicile.**
- **Votre AppleTV DOIT avoir une ip fixée (soit par réservation DHCP soit dans les Réglages)**
- **L'AppleTV DOIT être dans le même réseau que votre jeedom (sans routage !!, elle est découverte par le protocole Bonjour)**
- Pour l'instant, les données de lecture sont renouvellées toutes les minutes SI vous avez cliqué sur Play VIA JEEDOM (Jusqu'à avoir cliqué sur Pause ou Stop). Car si je scan ces données de lecture en permanance, votre AppleTV sort de veille :'(
- Conseil : activez la mise en veille automatique pour contrer l'impossibilité de mettre en veille.

Configuration du plugin 
=======================

Après installation du plugin, il vous suffit de l’activer et de lancer les dépendances.

Configuration des équipements 
=============================

Ajout Rapide :
--------------
**SI** votre AppleTV a le Partage à domicile activé, Lancez un scan. Celle-ci s'ajoutera en désactivé et invisible.
Modifiez l'équipement créé et Activez-le + Placez le dans une pièce et visible.


Description Complète
--------------------
La configuration des équipements AppleTV est accessible à partir du menu
plugins puis Multimédia. Vous retrouvez ici :

-   un bouton pour lancer un scan.

-   un bouton pour afficher la configuration du plugin

-   un bouton qui vous donne une vue d'ensemble de tous vos équipements

-   enfin en dessous vous retrouvez la liste de vos équipements

En cliquant sur un de vos équipements vous arrivez sur la page
configuration de votre équipement comprenant 2 onglets, équipement et
commandes.

Onglet Equipement
-----------------

-   **Nom de l’équipement** : nom de votre équipement

-   **Activer** : permet de rendre votre équipement actif

-   **Visible** : le rend visible sur le dashboard

-   **Objet parent** : indique l’objet parent auquel appartient
    l’équipement

-   **Ip de l'AppleTV** : l'ip et port de votre AppleTV

-   **Credentials** : Crédentials récupérés au scan de votre AppleTV


Onglet Commandes
----------------
Il existe de nombreuses commandes. Toutes ne sont pas affichées par défaut. Vous pouvez les renommer, les afficher ou non les réorganiser. 

-   **Lecture** : Binaire permettant de déterminer si la lecture est en cours ou pas

-   **Bouton Lecture** : play : Effectue la même action que le bouton play/pause de la télécommande
-   **Bouton Pause** : pause : Effectue la même action que le bouton play/pause de la télécommande
-   **Bouton Stop** : stop : Effectue la même action que le bouton play/pause de la télécommande (idem bouton pause en fait)
-   **Bouton Bas** : down : Effectue la même action que un swipe vers le bas sur la télécommande.
-   **Bouton Haut** : up : Effectue la même action que un swipe vers le haut sur la télécommande.
-   **Bouton Gauche** : left : Effectue la même action que un swipe vers la gauche sur la télécommande.
-   **Bouton Droit** : right : Effectue la même action que un swipe vers la gauche sur la télécommande.
-   **Bouton Précédent** : previous : Passe au morceau Précédent
-   **Bouton Suivant** : next : Passe au morceau Suivant
-   **Bouton Menu** : menu : Effectue la même action que le bouton Menu sur la télécommande.
-   **Bouton Sélection** : select : Effectue la même action qu'un click sur la télécommande.
-   **Bouton Home** : top_menu : Effectue la même action que le bouton Home sur la télécommande.

-   **Commandes Groupées** : (à venir) : Permet de grouper plusieurs commandes sur un seul appel (plus rapide d'exécution car une seule session !!!)

-   **Artwork** : artwork_save : Image de la lecture en cours si disponible.
-   **Artiste** : artist : Affiche l'artiste de la lecture en cours si disponible.
-   **Titre** : title : Affiche le titre de la lecture en cours si disponible.
-   **Album** : album : Affiche l'album de la lecture en cours si disponible.
-   **Type Media** : media_type : Affiche le type de media de la lecture en cours si disponible. (souvent Unknown)
-   **Position** : position : Affiche la position dans la lecture en cours si disponible.
-   **Temps total** : total_time : Affiche le temps total de la lecture en cours si disponible. (peut-être inclu dans la position)



