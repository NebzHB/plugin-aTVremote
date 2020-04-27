/* jshint esversion: 6,node: true,-W041: false */
const LogType = require('./utils/logger.js').logType;
const Logger = require('./utils/logger.js').getInstance();
const express = require('express');
const fs = require('fs');
const spawn = require('child_process').spawn;

Logger.setLogLevel(LogType.DEBUG);
var conf={};
conf.preConnect=[];
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
		default:
			if(val.includes('|')) {
				conf.preConnect = val.split('|');
			} else {
				conf.preConnect.push(val);
			}
		break;
	}
});

const jsend = require('./utils/jeedom.js')('aTVremote',conf.urlJeedom,conf.apiKey);


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
const app = express();
var server = null;
var isReady=false;
var lastErrorMsg="";

function connectATV(mac) {
	if(!aTVs.cmd[mac]) {
		aTVs.cmd[mac] = spawn(__dirname+'/atvremote/bin/atvremote', ['cli','-i',mac],{cwd:__dirname+'/images'});
		aTVs.cmd[mac].stdout.on('data', function(data) {
			data=data.toString();
			if(data.includes("Enter commands and press enter")) {
				if(!isReady) {
					server = app.listen(conf.serverPort, () => {
						Logger.log("Démon prêt et à l'écoute !",LogType.INFO);
						isReady=true;
					});
				}
				Logger.log('Connecté au canal des commandes de '+mac+' !',LogType.INFO);
				return;
			}
			if(data != "pyatv> ") {
				data=data.replace('\npyatv> ','');
				
				if(data.includes('PowerState.')) {
					jsend({eventType: 'powerstate', data : data, mac: mac});
				}
				if(data.includes('App: ')) {
					jsend({eventType: 'app', data : data, mac: mac});
				}
				if(data[0] == '+' || data[0] == 'v') {
					jsend({eventType: 'hash', data : data, mac: mac});
				}
				
				Logger.log('cmd |'+data,LogType.INFO);
			}
		});

		aTVs.cmd[mac].stderr.on('data', function(data) {
		  Logger.log(data.toString(),LogType.ERROR);
		  lastErrorMsg=data;
		});

		aTVs.cmd[mac].on('exit', function(code) {
			let mac=this.spawnargs[3];
			if(code != 0) {
				Logger.log('exit code: ' + code,LogType.WARNING);
				if(lastErrorMsg && lastErrorMsg.includes('Could not find any Apple TV on current network')) {
					Logger.log('removing '+mac+' from aTVs...',LogType.WARNING);
					delete aTVs.cmd[mac];
					Logger.log('Déconnecté du canal des commandes de '+mac,LogType.INFO);
				} else {
					delete aTVs.cmd[mac];
					Logger.log('Reconnection au canal des commandes...',LogType.WARNING);
					setTimeout(connectATV,100,mac);
				}
			} else {
				Logger.log('Déconnecté du canal des commandes de '+mac,LogType.INFO);
			}
			lastErrorMsg="";
		});
	}
	
		
	if(!aTVs.msg[mac]) {
		aTVs.msg[mac] = spawn(__dirname+'/atvremote/bin/atvremote', ['push_updates','-i',mac]);
		//aTVs.msg[mac] = spawn(__dirname+'/atvremote/bin/python3.7', [__dirname+'/atvscriptNew.py','push_updates','-i',mac]);
		aTVs.msg[mac].stdout.on('data', function(data) {
			data=data.toString();
			if(data.includes("Press ENTER to stop")) {
				if(!isReady) {
					server = app.listen(conf.serverPort, () => {
						Logger.log("Démon prêt et à l'écoute !",LogType.INFO);
						isReady=true;
					});
				}
				Logger.log('Connecté au canal des messages de '+mac+' !',LogType.INFO);
				data=data.replace('Press ENTER to stop','');
			}
			
			if(data.includes('Media type:')) {
				data = data.split("\n").filter(function (el) {
					return el != null && el != '' && el != '--------------------';
				});
				jsend({eventType: 'playing', data : data, mac: mac});
			}
			Logger.log('msg |'+data,LogType.INFO);
		});

		aTVs.msg[mac].stderr.on('data', function(data) {
		  Logger.log(data.toString(),LogType.ERROR);
		  lastErrorMsg=data;
		});

		aTVs.msg[mac].on('exit', function(code) {
			let mac=this.spawnargs[3];
			if(code != 0) {
				Logger.log('exit code: ' + code,LogType.WARNING);
				if(lastErrorMsg && lastErrorMsg.includes('Could not find any Apple TV on current network')) {
					Logger.log('removing '+mac+' from aTVs...',LogType.WARNING);
					delete aTVs.msg[mac];
					Logger.log('Déconnecté du canal des messages de '+mac,LogType.INFO);
				} else {
					delete aTVs.msg[mac];
					Logger.log('Reconnection au canal des messages...',LogType.WARNING);
					setTimeout(connectATV,100,mac);
				}
			} else {
				Logger.log('Déconnecté du canal des messages de '+mac,LogType.INFO);
			}
			lastErrorMsg="";
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
	if(hasRemoved) Logger.log('Déconnecté de '+mac,LogType.INFO);
}

app.get('/cmd', function(req,res){
	if(req.query.cmd=="push_updates") {
		Logger.log(req.query.cmd+' unsupported',LogType.INFO);
		res.status(200).json({'result':'ko','msg':'unsupported'});
	}
	var mac=req.query.mac.toUpperCase();
	Logger.log("Exécution commande "+req.query.cmd+' sur '+mac,LogType.DEBUG);
	aTVs.cmd[mac].stdin.write(req.query.cmd+'\n');
	res.status(200).json({'result':'ok'});		
});
app.get('/connect', function(req,res){
	var mac=req.query.mac.toUpperCase();
	if(!aTVs.cmd[mac] || !aTVs.msg[mac]) {
		Logger.log("Connexion sur "+mac+"...",LogType.INFO);
		connectATV(mac);
		res.status(200).json({'result':'ok'});		
	} else {
		Logger.log("Déjà connecté sur "+mac,LogType.WARNING);
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
		Logger.log("Pas connecté sur "+mac,LogType.WARNING);
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
	if(res)
		res.status(200).json({'result':'stopped'});	
};
app.get('/stop', stop);
app.use(function(err, req, res, _next) {
  Logger.log(err,LogType.ERROR);
  res.status(200).json({'result':'ko','msg':err});
});


for(const mac of conf.preConnect) {
	connectATV(mac);
}
if(conf.preConnect.length==0) {
	if(!isReady) {
		/** Listen **/
		server = app.listen(conf.serverPort, () => {
			Logger.log("Démon prêt et à l'écoute !",LogType.INFO);
			isReady=true;
		});
	}
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