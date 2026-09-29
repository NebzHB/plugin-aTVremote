"use strict";

var logType={
  ERROR : {value:0, txt:'Error'},
  WARNING : {value:1, txt:'Warning'},
  INFO : {value:2, txt:'Info'},
  DEBUG : {value:3, txt:'Debug'},
};

var instance = null;

class Logger {
  constructor() {
    this._logLevel = logType.ERROR;
  }

  setLogLevel(level) {
    this._logLevel = level;
  }

  log(message,level) {
    level = typeof level !== 'undefined' ? level : logType.INFO;
    if ( level.value > this._logLevel.value ) {return;}
	let logString="[" + (new CustomDate()).toString() + "][" + level.txt.toUpperCase() + "] : " + message;
    console.log(logString);
	logString=null;
  }
}

class CustomDate extends Date {
  constructor()
  {
    super();
  }

  getFullDay(){
     if (this.getDate() < 10) {
         return '0' + this.getDate();
     }
     return this.getDate();
  }

  getFullMonth() {
     var t = this.getMonth() + 1;
     if (t < 10) {
         return '0' + t;
     }
     return t;
  }

  getFullHours() {
     if (this.getHours() < 10) {
         return '0' + this.getHours();
     }
     return this.getHours();
  }

  getFullMinutes() {
     if (this.getMinutes() < 10) {
         return '0' + this.getMinutes();
     }
     return this.getMinutes();
  }

  getFullSeconds() {
     if (this.getSeconds() < 10) {
         return '0' + this.getSeconds();
     }
     return this.getSeconds();
  }

  toString() {
    return this.getFullDay() + "-" + this.getFullMonth() + "-" + this.getFullYear() + " " + this.getFullHours() + ":" + this.getFullMinutes() + ":" + this.getFullSeconds();
  }
}

exports.logType = logType;
exports.getInstance = function() {
  if (instance == null) {
    instance = new Logger();
  }
  return instance;
};
