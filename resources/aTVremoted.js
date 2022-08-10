/* jshint esversion: 6,node: true,-W041: false */
const LogType = require('./utils/logger.js').logType;
const Logger = require('./utils/logger.js').getInstance();
const express = require('express');
const fs = require('fs');
const spawn = require('child_process').spawn;

Logger.setLogLevel(LogType.DEBUG);
var conf={};
conf.preConnect3=[];
conf.preConnect4=[];
conf.preConnectHP=[];
var hasOwnProperty = Object.prototype.hasOwnProperty;

// Logger.log("env : "+process.env.NODE_ENV,LogType.DEBUG);

// Args handling
process.argv.forEach(function(val, index) {
	switch (index){
		case 0:break;
		case 1:break;
		case 2: conf.urlJeedom = val; break;
		case 3: conf.apiKey = val; break;
		case 4: conf.serverPort = val; break;
		case 5:
			conf.logLevel = val;
			if (conf.logLevel == 'debug') {Logger.setLogLevel(LogType.DEBUG);}
			else if (conf.logLevel == 'info') {Logger.setLogLevel(LogType.INFO);}
			else if (conf.logLevel == 'warning') {Logger.setLogLevel(LogType.WARNING);}
			else {Logger.setLogLevel(LogType.ERROR);}
		break;
		case 6: // atv3
			if(val != "None") {
				conf.preConnect3 = val.split(',');
			}
		break;
		case 7: // other atv
			if(val != "None") {
				conf.preConnect4 = val.split(',');
			}
		break;
		case 8: // other
			if(val != "None") {
				conf.preConnectHP = val.split(',');
			}
		break;
	}
});

const jsend = require('./utils/jeedom.js')('aTVremote',conf.urlJeedom,conf.apiKey,'1',conf.logLevel);


// display starting
Logger.log("Démarrage démon aTVremote...", LogType.INFO);
for(var name in conf) {
	if (hasOwnProperty.call(conf,name)) {
		Logger.log(name+" = "+((typeof conf[name] == "object")?JSON.stringify(conf[name]):conf[name]), LogType.DEBUG);
	}
}


// display starting
var aTVs = {};
aTVs.cmd = [];
aTVs.msg = [];
// aTVs.previousMsg= [];
const app = express();
var server = null;
var isReady=false;
var lastErrorMsg="";

function connectATV(mac,version) {
	var pairingKeyAirplay=null;
	var pairingKeyCompanion=null;
	if(version == 3 || version == 4) {
		
		if (fs.existsSync(__dirname+'/../data/'+mac+'-airplay.key')) {
			pairingKeyAirplay=fs.readFileSync(__dirname+'/../data/'+mac+'-airplay.key');
		} else {
			Logger.log("Pas de clé airplay trouvée pour l'Apple TV "+mac+", merci de faire l'appairage avant",LogType.WARNING);
		}
		if(version != 3) {
			if (fs.existsSync(__dirname+'/../data/'+mac+'-companion.key')) {
				pairingKeyCompanion=fs.readFileSync(__dirname+'/../data/'+mac+'-companion.key');
			} else {
				Logger.log("Pas de clé companion trouvée pour l'Apple TV "+mac+", merci de faire l'appairage avant",LogType.WARNING);
			}
		}
	}
	
	// création canal des commandes
	if(!aTVs.cmd[mac]) {
		if (!fs.existsSync(__dirname+'/../core/img/artworks')) {
			fs.mkdirSync(__dirname+'/../core/img/artworks');
		}
		const atvremoteParams = ['-i',mac];
		if(pairingKeyAirplay) { atvremoteParams.push('--protocol','airplay','--airplay-credentials',pairingKeyAirplay); }
		if(pairingKeyCompanion) { atvremoteParams.push('--protocol','companion','--companion-credentials',pairingKeyCompanion); }
		atvremoteParams.push('cli');
		aTVs.cmd[mac] = spawn(__dirname+'/atvremote/bin/atvremote', atvremoteParams,{cwd:__dirname+'/../core/img/artworks'});
		Logger.log('SPAWN CMD : '+__dirname+'/atvremote/bin/atvremote '+atvremoteParams.join(' '),LogType.DEBUG);
		aTVs.cmd[mac].stdout.on('data', function(data) {
			data=data.toString();
			if(data.includes("Enter commands and press enter")) {
				return;
			}
			if(data != "pyatv> ") {
				data=data.replace('\npyatv> ','').replace('\npyatv> ','').replace('pyatv> ','');
				
				if(data.match(/^[0-9]{0,3}\.[0-9]$/)) {
					jsend({eventType: 'volume', data : data, mac: mac});
				} else if(data.includes('Media type')) {
					let jsonData={'simplifiedPlaying':true};
					for(const line of data.split('\n')) {
						const fields=line.trim().split(': ');
						const key=fields[0].trim().replace(' ','_').toLowerCase();
						if(fields[1]) {
							let newValue=fields[1].trim();
							if(fields.length > 2) {
								fields.shift();
								newValue=fields.join(': ');
							} 
							jsonData[key]=newValue;
						}
					}
					jsend({eventType: 'playing', data : JSON.stringify(jsonData), mac: mac});
				} else if(data.includes('PowerState.')) {
					jsend({eventType: 'powerstate', data : data, mac: mac});
				} else if(data.includes('App: ')) {
					jsend({eventType: 'app', data : data, mac: mac});
				} else if(data.includes('Could not find any Apple TV on current network')) {
					lastErrorMsg=data;
				} else if(data.includes('No artwork is currently available')) {
					jsend({eventType: 'reaskArtwork', mac: mac});
				} 
				
				
				Logger.log('cmd | '+data,LogType.DEBUG);
			}
		});

		aTVs.cmd[mac].stderr.on('data', function(data) {
			Logger.log("CMD CHAN ERR :"+data.toString(),LogType.ERROR);
			lastErrorMsg=data;
			aTVs.cmd[mac].kill('SIGHUP');
		});

		aTVs.cmd[mac].on('exit', function(code) {
			const mac=this.spawnargs[2];
			Logger.log('Exit code CMD : ' + code,LogType.DEBUG);
			if(code != 0) {
				if(lastErrorMsg && lastErrorMsg.includes('Could not find any Apple TV on current network')) {
					delete aTVs.cmd[mac];
					Logger.log('Déconnecté du canal des commandes de '+mac,LogType.DEBUG);
				} else {
					delete aTVs.cmd[mac];
					Logger.log('Reconnection au canal des commandes...',LogType.DEBUG);
					setTimeout(connectATV,100,mac,version);
				}
			} else {
				Logger.log('Déconnecté du canal des commandes de '+mac,LogType.DEBUG);
			}
			lastErrorMsg="";
		});
		aTVs.cmd[mac].on('spawn', function() {
			Logger.log('Connecté au canal des commandes de '+mac+' !',LogType.INFO);
		});
	}
	
	// création canal des messages
	if(!aTVs.msg[mac] && version != 3) {
		// aTVs.previousMsg[mac]="";
		const atvremoteParams = ['-i',mac];
		if(pairingKeyAirplay) { atvremoteParams.push('--protocol','airplay','--airplay-credentials',pairingKeyAirplay); }
		// if(pairingKeyCompanion) { atvremoteParams.push('--protocol','companion','--companion-credentials',pairingKeyCompanion); } // crash when added
		atvremoteParams.push('push_updates');
		aTVs.msg[mac] = spawn(__dirname+'/atvremote/bin/atvscript', atvremoteParams);
		Logger.log('SPAWN MSG : '+__dirname+'/atvremote/bin/atvscript '+atvremoteParams.join(' '),LogType.DEBUG);
		aTVs.msg[mac].stdout.on('data', function(data) {
			// var comparingData;
			var sent;
			for(var stringData of data.toString().split("\n")) {
				if(stringData == '') { continue; }
				
				/* comparingData=stringData.replace(/"datetime": "[^"]*", /gi,'').replace(/"position": [^,]*, /gi,'');
				if(comparingData == aTVs.previousMsg[mac]) {
					return true; // Ignore same message than the previous one
				} */
				sent="";
				if(stringData.includes('power_state')) {
					sent="envoyé à jeedom ";
					jsend({eventType: 'powerstate', data : stringData, mac: mac});
				} else if(stringData.includes('media_type')) {
					// aTVs.previousMsg[mac]=comparingData;
					sent="envoyé à jeedom ";
					jsend({eventType: 'playing', data : stringData, mac: mac});	
				} else if(stringData.includes('connection": "closed')) {
					delete aTVs.msg[mac];
					Logger.log('Reconnection au canal des messages...',LogType.DEBUG);
					setTimeout(connectATV,100,mac,version);
				} /* else if(stringData.includes('push_updates": "finished')) {
					delete aTVs.msg[mac];
					Logger.log('Reconnection au canal des messages...',LogType.DEBUG);
					setTimeout(connectATV,100,mac,version);
				} */ else if(stringData.includes('error": "device_not_found')) {
					delete aTVs.msg[mac];
					Logger.log('Déconnecté du canal des messages de '+mac,LogType.DEBUG);
				} else if(stringData.includes('connection": "lost')) {
					lastErrorMsg=stringData;
				}
				Logger.log('msg '+sent+'|'+stringData,LogType.DEBUG);
			}
		});

		aTVs.msg[mac].stderr.on('data', function(data) {
			Logger.log("MSG CHAN ERR :"+data.toString(),LogType.ERROR);
			lastErrorMsg=data;
		});

		aTVs.msg[mac].on('exit', function(code) {
			const mac=this.spawnargs[2];
			Logger.log('Exit code MSG : ' + code,LogType.DEBUG);
			if(code != 0) {
				if(lastErrorMsg && lastErrorMsg.includes('Could not find any Apple TV on current network')) {
					Logger.log('Removing '+mac+' from aTVs...',LogType.DEBUG);
					delete aTVs.msg[mac];
					Logger.log('Déconnecté du canal des messages de '+mac,LogType.DEBUG);
				} else {
					delete aTVs.msg[mac];
					Logger.log('Reconnection au canal des messages...',LogType.DEBUG);
					setTimeout(connectATV,100,mac,version);
				}
			} else if(lastErrorMsg && lastErrorMsg.includes('connection": "lost')) {
				delete aTVs.msg[mac];
				Logger.log('Reconnection au canal des messages...',LogType.DEBUG);
				setTimeout(connectATV,100,mac,version);
			} else {
				Logger.log('Removing '+mac+' from aTVs...',LogType.DEBUG);
				delete aTVs.msg[mac];
				Logger.log('Déconnecté du canal des messages de '+mac,LogType.DEBUG);
			}
	
			lastErrorMsg="";
		});
		aTVs.msg[mac].on('spawn', function() {
			Logger.log('Connecté au canal des messages de '+mac+' !',LogType.INFO);
		});
	}
}

function removeATV(mac) {
	var hasRemoved=false;
	if(aTVs.cmd[mac]) {
		aTVs.cmd[mac].stdin.write('exit\n');
		delete aTVs.cmd[mac];
		hasRemoved=true;
	}
	if(aTVs.msg[mac]) {
		aTVs.msg[mac].stdin.write('\n');
		delete aTVs.msg[mac];
		hasRemoved=true;
	}
	if(hasRemoved) { Logger.log('Déconnecté de '+mac,LogType.INFO); }
}

app.get('/cmd', function(req,res){
	var mac = req.query.mac.toUpperCase();
	var cmds = req.query.cmd.replace(' ','');
	if(cmds.includes("push_updates")) {
		Logger.log(cmds+' unsupported because of push_updates',LogType.INFO);
		res.status(200).json({'result':'ko','msg':'unsupported'});
	}
	if(cmds.includes('|')) {
		cmds=cmds.split('|');
	} else {
		cmds=[cmds];
	}
	for(const cmd of cmds) {
		Logger.log("Exécution commande "+cmd+' sur '+mac,LogType.DEBUG);
		aTVs.cmd[mac].stdin.write(cmd+'\n');
	}
	res.status(200).json({'result':'ok'});		
});
app.get('/connect', function(req,res){
	var mac=req.query.mac.toUpperCase();
	if(!aTVs.cmd[mac] || !aTVs.msg[mac]) {
		Logger.log("Connexion sur "+mac+"...",LogType.INFO);
		connectATV(mac,parseInt(req.query.version));
		res.status(200).json({'result':'ok'});		
	} else {
		Logger.log("Déjà connecté sur "+mac,LogType.INFO);
		res.status(200).json({'result':'ko','msg':'alreadyConnected'});		
	}
});
app.get('/disconnect', function(req,res){
	var mac=req.query.mac.toUpperCase();
	if(aTVs.cmd[mac] || aTVs.msg[mac]) {
		Logger.log("Déconnexion de "+mac+"...",LogType.INFO);
		removeATV(mac);
		res.status(200).json({'result':'ok'});		
	} else {
		Logger.log("Pas connecté sur "+mac,LogType.INFO);
		res.status(200).json({'result':'ko','msg':'notConnected'});		
	}
});
app.get('/test', function(req,res){
		res.status(200).json({'result':'ok'});	
});
var stop=function(req, res) {
	isReady=false;
	Logger.log('Recu de jeedom: Demande d\'arret',LogType.INFO);
	
	for(var atvc of aTVs.cmd) {
		atvc.stdin.write('exit\n');
	}
	for(var atvm of aTVs.msg) {
		atvm.stdin.write('\n');
	}
	server.close(() => {
		process.exit(0);
	});
	if(res) {
		res.status(200).json({'result':'stopped'});
	}
};
app.get('/stop', stop);
app.use(function(err, req, res, _next) {
  Logger.log(err,LogType.ERROR);
  res.status(200).json({'result':'ko','msg':err});
});


for(const mac of conf.preConnect3) {
	connectATV(mac,3);
}
for(const mac of conf.preConnect4) {
	connectATV(mac,4);
}
for(const mac of conf.preConnectHP) {
	connectATV(mac,'HP');
}

if(!isReady) {
	/** Listen **/
	server = app.listen(conf.serverPort, () => {
		Logger.log("Démon prêt et à l'écoute !",LogType.INFO);
		isReady=true;
	});
}

/**
 * Restarts the workers.
 */
process.on('SIGHUP', function() {
	Logger.log('Recu SIGHUP',LogType.DEBUG);
	stop();
});
/**
 * Gracefully Shuts down the workers.
 */
process.on('SIGTERM', function() {
	Logger.log('Recu SIGTERM',LogType.DEBUG);
	stop();
});
