"use strict";

const request = require('request');


var busy = false;
var jeedomSendQueue = [];

var thisUrl="";
var thisApikey="";
var thisType="";
var this42="";
var thislogLevel="";

var processJeedomSendQueue = function()
{
	// console.log('Nombre de messages en attente de traitement : ' + jeedomSendQueue.length);
	var nextMessage = jeedomSendQueue.shift();

	if (!nextMessage) {
		busy = false;
		return;
	}
	// console.log('Traitement du message : ' + JSON.stringify(nextMessage));
	request.post({url:thisUrl, form:nextMessage.data}, function(err, response, body) {
		if(err)
		{
			if(thislogLevel == 'debug') { console.error("Erreur communication avec Jeedom (retry "+nextMessage.tryCount+"/5): ",err,response,body); }
			if (nextMessage.tryCount < 5)
			{
				nextMessage.tryCount++;
				jeedomSendQueue.unshift(nextMessage);
			}
		}
		else if(thislogLevel == 'debug' && response.body.trim() != '') { console.log("Réponse de Jeedom : ", response.body); }
		setTimeout(processJeedomSendQueue, 0.01*1000);
	});
};

var sendToJeedom = function(data)
{
	// console.log("sending with "+thisUrl+" and "+thisApikey);
	if(this42 == '0') {
		data.type = thisType;
		data.apikey= thisApikey;
	} else {
		data.type = 'event';
		data.apikey= thisApikey;
		data.plugin= thisType;
	}
	var message = {};
	message.data = data;
	message.tryCount = 0;
	// console.log("Ajout du message " + JSON.stringify(message) + " dans la queue des messages a transmettre a Jeedom");
	jeedomSendQueue.push(message);
	if (busy) {return;}
	busy = true;
	processJeedomSendQueue();
};


module.exports = ( type, url, apikey, jeedom42, logLevel ) => { 
	// console.log("importing jeedom with "+url+" and "+apikey);
	thisUrl=url;
	thisApikey=apikey;
	thisType=type;
	this42=jeedom42;
	thislogLevel=logLevel;
	return sendToJeedom;
};
