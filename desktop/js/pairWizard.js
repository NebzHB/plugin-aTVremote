/* Assistant d'appairage Apple TV par le démon Python.
 * Pour chaque protocole à appairer (AirPlay puis Companion) : le démon demande l'appairage, l'Apple TV
 * affiche un code à 4 chiffres, on le saisit ici, la clé est enregistrée et la connexion relancée.
 */
var aTVPairWizard = (function () {
	var PROTOCOLS = {
		airplay: {
			name: 'AirPlay',
			key: 'pairingKeyAirplay',
			icon: 'fas fa-play',
			role: "{{Télécommande, lecture, état et pochette}}",
			tips: [
				"{{Sur l'Apple TV : Réglages > AirPlay et HomeKit > AirPlay : Oui}}",
				"{{Autoriser l'accès : « Tout le monde » ou « Toute personne sur le même réseau »}}"
			]
		},
		companion: {
			name: 'Companion',
			key: 'pairingKeyCompanion',
			icon: 'fas fa-th',
			role: "{{Lancement des apps, allumer / éteindre, clavier}}",
			tips: [
				"{{Sur l'Apple TV : Réglages > AirPlay et HomeKit > Autoriser l'accès : « Tout le monde » ou « Toute personne sur le même réseau »}}",
				"{{Si l'Apple TV propose d'appairer une télécommande, acceptez puis attendez le code}}"
			]
		}
	};
	var state = null;
	var $overlay = null;

	function esc(text) {
		return $('<div>').text(text === undefined || text === null ? '' : String(text)).html();
	}

	function ajax(action, data, done, fail) {
		$.ajax({
			type: 'POST',
			url: 'plugins/aTVremote/core/ajax/aTVremote.ajax.php',
			data: $.extend({action: action, id: state.id}, data),
			dataType: 'json',
			global: false,
			timeout: 70000,
			error: function (request, status) {
				if (fail) fail(status === 'timeout' ? "{{Pas de réponse de Jeedom}}" : "{{Erreur de communication avec Jeedom}}");
			},
			success: function (res) {
				if (res.state != 'ok') {
					if (fail) fail(res.result);
					return;
				}
				if (done) done(res.result);
			}
		});
	}

	function render(html, focusSelector) {
		var steps = '';
		if (state.queue.length > 1) {
			steps = '<ol class="apw-steps">' + state.queue.map(function (proto, i) {
				var cls = (i < state.index || state.done[proto]) ? 'apw-done' : (i === state.index && state.step !== 'intro' ? 'apw-current' : '');
				return '<li class="' + cls + '">' + (i + 1) + '. ' + esc(PROTOCOLS[proto].name) + '</li>';
			}).join('') + '</ol>';
		}
		$overlay.find('.apw-content').html(steps + html);
		var $focus = $overlay.find(focusSelector || '.apw-actions .btn-primary, .apw-actions .btn-success').first();
		if ($focus.length) setTimeout(function () { $focus.trigger('focus'); }, 50);
	}

	function actions(buttons) {
		return '<div class="apw-actions">' + buttons.map(function (b) {
			return '<button type="button" class="btn ' + b.cls + '" data-apw="' + b.action + '">' + b.label + '</button>';
		}).join('') + '</div>';
	}

	function current() {
		return state.queue[state.index];
	}

	/* ---------- écrans ---------- */

	function showIntro() {
		state.step = 'intro';
		var list = state.queue.map(function (proto) {
			var p = PROTOCOLS[proto];
			var paired = state.paired[proto];
			return '<li><span class="apw-icon"><i class="' + p.icon + '"></i></span><div><b>' + esc(p.name) + '</b>' +
				'<span class="apw-muted">' + p.role + '</span><br>' +
				'<span class="apw-badge' + (paired ? ' apw-ok' : '') + '">' + (paired ? "{{Déjà appairé, sera refait}}" : "{{À appairer}}") + '</span></div></li>';
		}).join('');
		render(
			'<h3 class="apw-title">{{Appairer}} ' + esc(state.name) + '</h3>' +
			'<p class="apw-text">{{Jeedom a besoin d\'une autorisation de l\'Apple TV pour la piloter. Pour chaque protocole, un code à 4 chiffres va s\'afficher sur la télé : il suffit de le recopier ici.}}</p>' +
			'<p class="apw-text"><b>{{Avant de commencer}}</b> : {{allumez la télé et l\'Apple TV (pas en veille) et restez devant l\'écran.}}</p>' +
			'<ul class="apw-protocols">' + list + '</ul>' +
			actions([
				{cls: 'btn-default', action: 'cancel', label: "{{Annuler}}"},
				{cls: 'btn-primary', action: 'start', label: '<i class="fas fa-link"></i> {{Commencer}}'}
			])
		);
	}

	function showWaiting(text) {
		render(
			'<h3 class="apw-title">' + esc(PROTOCOLS[current()].name) + '</h3>' +
			'<div class="apw-status"><span class="apw-spinner"></span><span>' + text + '</span></div>' +
			actions([{cls: 'btn-default', action: 'cancel', label: "{{Annuler}}"}])
		);
	}

	function showPin(error) {
		state.step = 'pin';
		var p = PROTOCOLS[current()];
		render(
			'<h3 class="apw-title">{{Code affiché sur la télé}}</h3>' +
			'<p class="apw-text">{{L\'Apple TV affiche un code à 4 chiffres pour}} <b>' + esc(p.name) + '</b>. {{Saisissez-le ci-dessous.}}</p>' +
			'<div class="apw-tv" aria-hidden="true"><small>{{Code}} ' + esc(p.name) + '</small><span>•</span><span>•</span><span>•</span><span>•</span></div>' +
			'<input class="apw-pin' + (error ? ' apw-shake' : '') + '" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="one-time-code" placeholder="••••" aria-label="{{Code à 4 chiffres}}" />' +
			(error ? '<p class="apw-center" style="color:var(--al-danger-color,#d9534f)">' + esc(error) + '</p>' : '') +
			'<details class="apw-help"><summary>{{Le code ne s\'affiche pas ?}}</summary><ul>' +
			'<li>{{Vérifiez que la télé affiche bien l\'Apple TV et qu\'elle n\'est pas en veille.}}</li>' +
			p.tips.map(function (t) { return '<li>' + t + '</li>'; }).join('') +
			'<li>{{Le code reste valable environ 3 minutes : au-delà, recommencez.}}</li>' +
			'</ul></details>' +
			actions([
				{cls: 'btn-default', action: 'cancel', label: "{{Annuler}}"},
				{cls: 'btn-default', action: 'restart', label: '<i class="fas fa-redo"></i> {{Nouveau code}}'},
				{cls: 'btn-primary', action: 'validate', label: '<i class="fas fa-check"></i> {{Valider}}'}
			]),
			'.apw-pin'
		);
	}

	function showError(message) {
		state.step = 'error';
		render(
			'<span class="apw-big-icon apw-error"><i class="fas fa-times"></i></span>' +
			'<h3 class="apw-title apw-center" style="margin-right:0">{{Appairage}} ' + esc(PROTOCOLS[current()].name) + ' {{impossible}}</h3>' +
			'<p class="apw-text apw-center">' + esc(message) + '</p>' +
			actions([
				{cls: 'btn-default', action: 'cancel', label: "{{Fermer}}"},
				{cls: 'btn-primary', action: 'restart', label: '<i class="fas fa-redo"></i> {{Réessayer}}'}
			])
		);
	}

	function showDone() {
		state.step = 'done';
		var names = state.queue.map(function (proto) { return PROTOCOLS[proto].name; }).join(' {{et}} ');
		render(
			'<span class="apw-big-icon"><i class="fas fa-check"></i></span>' +
			'<h3 class="apw-title apw-center" style="margin-right:0">' + esc(state.name) + ' {{est appairée}}</h3>' +
			'<p class="apw-text apw-center">' + esc(names) + ' : {{clé enregistrée, la connexion a été relancée. Vous pouvez fermer cette fenêtre.}}</p>' +
			actions([{cls: 'btn-success', action: 'close', label: "{{Terminer}}"}])
		);
	}

	/* ---------- déroulement ---------- */

	function begin() {
		state.step = 'begin';
		showWaiting("{{Demande d'appairage à l'Apple TV…}}");
		ajax('pairBegin', {protocol: current()}, function (result) {
			if (!state) return;
			if (!result.device_provides_pin) { // rare : c'est à nous de donner le code à l'appareil
				state.step = 'pin-device';
				render(
					'<h3 class="apw-title">{{Code à saisir sur l\'Apple TV}}</h3>' +
					'<div class="apw-tv"><small>' + esc(PROTOCOLS[current()].name) + '</small>' +
					String(result.pin).split('').map(function (d) { return '<span>' + esc(d) + '</span>'; }).join('') + '</div>' +
					actions([
						{cls: 'btn-default', action: 'cancel', label: "{{Annuler}}"},
						{cls: 'btn-primary', action: 'validate', label: "{{C'est fait}}"}
					])
				);
				return;
			}
			showPin();
		}, function (message) {
			if (state) showError(message);
		});
	}

	function validate() {
		var pin = '';
		if (state.step === 'pin') {
			pin = String($overlay.find('.apw-pin').val() || '').replace(/\D/g, '');
			if (pin.length !== 4) {
				showPin("{{Le code fait 4 chiffres}}");
				return;
			}
		}
		state.step = 'finish';
		showWaiting("{{Vérification du code…}}");
		ajax('pairFinish', {protocol: current(), pin: pin}, function (result) {
			if (!state) return;
			var proto = current();
			state.done[proto] = true;
			$('.eqLogicAttr[data-l1key=configuration][data-l2key=' + PROTOCOLS[proto].key + ']').value(result.credentials);
			if (state.index < state.queue.length - 1) {
				state.index++;
				begin();
			} else {
				showDone();
			}
		}, function (message) {
			if (state) showError(message);
		});
	}

	function close() {
		if (state && (state.step === 'pin' || state.step === 'pin-device' || state.step === 'begin')) {
			ajax('pairCancel', {protocol: current()}); // libère l'appairage en cours côté démon
		}
		var reload = state && Object.keys(state.done).length > 0;
		state = null;
		$overlay.attr('hidden', true).find('.apw-content').empty();
		$('body').css('overflow', '');
		if (reload) $('#div_alert').showAlert({message: "{{Appairage terminé, clé enregistrée}}", level: 'success'});
	}

	function build() {
		$overlay = $('<div class="apw-overlay" hidden role="dialog" aria-modal="true" aria-label="{{Assistant d\'appairage}}">' +
			'<div class="apw-panel"><button type="button" class="apw-close" data-apw="cancel" aria-label="{{Fermer}}">&times;</button>' +
			'<div class="apw-content"></div></div></div>').appendTo('body');
		$overlay.on('click', '[data-apw]', function () {
			switch ($(this).data('apw')) {
				case 'start': begin(); break;
				case 'validate': validate(); break;
				case 'restart': begin(); break;
				case 'cancel':
				case 'close': close(); break;
			}
		});
		$overlay.on('input', '.apw-pin', function () {
			var digits = this.value.replace(/\D/g, '').slice(0, 4);
			if (digits !== this.value) this.value = digits;
			if (digits.length === 4) validate(); // validation dès le 4e chiffre
		});
		$overlay.on('keydown', function (e) {
			if (e.key === 'Escape') close();
			if (e.key === 'Enter' && state && state.step === 'pin') validate();
		});
		$overlay.on('click', function (e) {
			if (e.target === this && state && state.step === 'done') close(); // clic hors de la fenêtre : seulement à la fin
		});
	}

	return {
		/* protocols : liste des protocoles à appairer (ordre AirPlay puis Companion) ; force : protocole cliqué, refait même s'il est déjà appairé */
		open: function (force) {
			if (!$overlay) build();
			var queue = [], paired = {};
			['airplay', 'companion'].forEach(function (proto) {
				var p = PROTOCOLS[proto];
				var needed = $('.eqLogicAttr[data-l1key=configuration][data-l2key=need' + p.name + 'Pairing]').value() == '1';
				paired[proto] = $('.eqLogicAttr[data-l1key=configuration][data-l2key=' + p.key + ']').value() != '';
				if (proto === force || (needed && !paired[proto])) queue.push(proto);
			});
			if (queue.length === 0) queue.push(force || 'airplay');
			state = {
				id: $('.eqLogicAttr[data-l1key=id]').value(),
				name: $('.eqLogicAttr[data-l1key=name]').value() || 'Apple TV',
				queue: queue,
				index: 0,
				paired: paired,
				done: {},
				step: 'intro'
			};
			$('body').css('overflow', 'hidden');
			$overlay.removeAttr('hidden');
			showIntro();
		}
	};
})();

$('.bt_pairDaemon').off('click').on('click', function () {
	aTVPairWizard.open($(this).data('protocol'));
});
