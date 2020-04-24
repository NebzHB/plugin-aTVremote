"use strict";

const request = require('request');


var busy = false;
var jeedomSendQueue = [];

var thisUrl="";
var thisApikey="";
var thisType="";

var processJeedomSendQueue = function()
{
	// console.log('Nombre de messages en attente de traitement : ' + jeedomSendQueue.length);
	var nextMessage = jeedomSendQueue.shift();

	if (!nextMessage) {
		busy = false;
		return;
	}
	// console.log('Traitement du message : ' + JSON.stringify(nextMessage));
	request.post({url:thisUrl, form:nextMessage.data}, function(err, _response, _body) {
		if(err)
		{
			// console.log(err);
			if (nextMessage.tryCount < 5)
			{
				nextMessage.tryCount++;
				jeedomSendQueue.unshift(nextMessage);
			}
		}
		else {
			// console.log("Response from Jeedom: " + response.statusCode);
			// console.log("Full Response: " + JSON.stringify(response));
		}
		setTimeout(processJeedomSendQueue, 0.01*1000);
	});
};

var sendToJeedom = function(data)
{
	// console.log("sending with "+thisUrl+" and "+thisApikey);
	data.type = thisType;
	data.apikey= thisApikey;
	var message = {};
	message.data = data;
	message.tryCount = 0;
	// console.log("Ajout du message " + JSON.stringify(message) + " dans la queue des messages a transmettre a Jeedom");
	jeedomSendQueue.push(message);
	if (busy) {return;}
	busy = true;
	processJeedomSendQueue();
};


module.exports = ( type, url, apikey ) => { 
	// console.log("importing jeedom with "+url+" and "+apikey);
	thisUrl=url;
	thisApikey=apikey;
	thisType=type;
	return sendToJeedom;
};
