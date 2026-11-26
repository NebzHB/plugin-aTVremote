/* aTVremote - widget mobile (partagé entre atv.html et homepod.html)
 *
 * Les templates fournissent :
 *  - les ids des commandes info dans data-id-<nom>="#<nom>_id#"
 *  - les valeurs initiales dans data-val-<nom>="#<nom>#"
 *  - les ids des commandes action dans data-cmd="#cmd_<nom>_id#"
 * Une valeur "#xxx#" non remplacée par Jeedom (commande absente de cet appareil)
 * n'est pas un id numérique : le contrôle correspondant est alors masqué.
 */
(function (w, $) {
	'use strict';

	var ICONS = {
		play: 'M8 5v14l11-7z',
		pause: 'M6 19h4V5H6v14zm8-14v14h4V5h-4z',
		next: 'M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z',
		prev: 'M6 6h2v12H6zm3.5 6l8.5 6V6z',
		stop: 'M6 6h12v12H6z',
		plus: 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z',
		minus: 'M19 13H5v-2h14v2z',
		volume: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z',
		shuffle: 'M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z',
		repeat: 'M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z',
		repeat_one: 'M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4zm-4-2V9h-1l-2 1v1h1.5v4H13z',
		power: 'M13 3h-2v10h2V3zm4.83 2.17l-1.42 1.42C17.99 7.86 19 9.81 19 12c0 3.87-3.13 7-7 7s-7-3.13-7-7c0-2.19 1.01-4.14 2.58-5.42L6.17 5.17C4.23 6.82 3 9.26 3 12c0 4.97 4.03 9 9 9s9-4.03 9-9c0-2.74-1.23-5.18-3.17-6.83z',
		home: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
		menu: 'M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z',
		back: 'M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z',
		chevron: 'M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z',
		refresh: 'M17.65 6.35A7.958 7.958 0 0012 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0112 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z',
		note: 'M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z'
	};

	function isId(v) {
		return /^\d+$/.test(String(v === undefined || v === null ? '' : v));
	}

	function icon(name) {
		return '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path fill="currentColor" d="' + ICONS[name] + '"/></svg>';
	}

	function isEmpty(v) {
		v = String(v === undefined || v === null ? '' : v).trim();
		return v === '' || v === '-' || v === 'Unknown';
	}

	/* Une valeur reçue d'un événement Jeedom peut être échappée en HTML : on la
	 * ramène en texte brut, qui sera ensuite posé avec .text() (jamais .html()). */
	function plain(v) {
		var t = document.createElement('textarea');
		t.innerHTML = String(v === undefined || v === null ? '' : v);
		return t.value;
	}

	function appName(v) {
		var p = String(v || '').split('.');
		if (p.length < 2) { return ''; }
		if (p[1] === 'apple') { return p[2] || ''; }
		if (p[2] === 'ios') { return p[3] || ''; }
		return p[1];
	}

	function init(root) {
		root = $(root);
		if (!root.length || root.data('amInit')) { return; }
		root.data('amInit', true);

		if (typeof jeedom.cmd.addUpdateFunction !== 'function') { // avant Jeedom 4.3
			jeedom.cmd.addUpdateFunction = function (id, func) { jeedom.cmd.update[id] = func; };
		}

		var marquee = root.attr('data-marquee') === 'scroll' ? 'scroll' : 'alternate';
		var st = {};
		var dragging = false;
		var dragTimer = null;

		/* icônes */
		root.find('[data-ic]').each(function () {
			$(this).html(icon($(this).attr('data-ic')));
		});

		if (!isId(root.attr('data-id-volume'))) { root.find('.am-vol-value').attr('hidden', ''); }

		/* commandes absentes de cet appareil */
		root.find('[data-cmd]').each(function () {
			if (!isId($(this).attr('data-cmd'))) { $(this).attr('hidden', ''); }
		});
		root.find('.am-repeat').each(function () {
			if (!isId($(this).attr('data-c-all'))) { $(this).attr('hidden', ''); }
		});
		root.find('.am-shuffle').each(function () {
			if (!isId($(this).attr('data-c-on'))) { $(this).attr('hidden', ''); }
		});

		function fitGroups() {
			root.find('.am-group').each(function () {
				var shown = $(this).find('button:not([hidden]), select:not([hidden]), input:not([hidden])').length;
				$(this).prop('hidden', shown === 0);
			});
		}

		function run(id) {
			if (isId(id)) { jeedom.cmd.execute({ id: id }); }
		}

		/* ---------- texte défilant seulement quand il déborde ---------- */
		function measure() {
			root.find('.am-line').each(function () {
				var box = this, span = $(box).children('span')[0];
				if (!span) { return; }
				$(box).removeClass('is-scroll is-alt is-loop');
				var over = span.scrollWidth - box.clientWidth;
				if (box.clientWidth > 0 && over > 2) {
					box.style.setProperty('--am-over', over + 'px');
					box.style.setProperty('--am-box', box.clientWidth + 'px');
					box.style.setProperty('--am-w', span.scrollWidth + 'px');
					box.style.setProperty('--am-dur', Math.max(6, span.scrollWidth / 25) + 's');
					$(box).addClass('is-scroll ' + (marquee === 'scroll' ? 'is-loop' : 'is-alt'));
				}
			});
		}
		var measureTimer = null;
		function measureLater() {
			clearTimeout(measureTimer);
			measureTimer = setTimeout(measure, 60);
			setTimeout(measure, 600); /* si le widget était masqué au chargement */
		}

		function setLine(name, v) {
			var line = root.find('.am-' + name);
			if (isEmpty(v)) {
				line.prop('hidden', true).children('span').text('');
			} else {
				line.prop('hidden', false).children('span').text(v);
			}
			measureLater();
		}

		function updateSub() {
			var parts = [];
			$.each(['genre', 'media_type', 'position'], function (i, k) {
				if (!isEmpty(st[k])) { parts.push(st[k]); }
			});
			root.find('.am-sub').prop('hidden', parts.length === 0).text(parts.join(' · '));
		}

		/* ---------- application d'une valeur d'info ---------- */
		var apply = {
			title: function (v) { setLine('title', v); },
			artist: function (v) { setLine('artist', v); },
			album: function (v) { setLine('album', v); },
			genre: updateSub,
			media_type: updateSub,
			position: updateSub,

			artwork_url: function (v) {
				var art = root.find('.am-art'), img = art.find('img');
				if (isEmpty(v)) {
					art.removeClass('has-art');
					root.find('.am-bg').css('background-image', 'none');
					return;
				}
				img.off('error.am load.am')
					.on('error.am', function () { art.removeClass('has-art'); root.find('.am-bg').css('background-image', 'none'); })
					.on('load.am', function () { art.addClass('has-art'); })
					.attr('src', v);
				root.find('.am-bg').css('background-image', 'url("' + String(v).replace(/"/g, '%22') + '")');
			},

			play_human: function (v) {
				root.find('.am-status-text').text(isEmpty(v) ? '' : v);
			},

			play_state: function (v) {
				var playing = String(v) === '1';
				root.toggleClass('is-playing', playing);
				root.find('.am-play').prop('hidden', playing || !isId(root.find('.am-play').attr('data-cmd')));
				root.find('.am-pause').prop('hidden', !playing || !isId(root.find('.am-pause').attr('data-cmd')));
				fitGroups();
			},

			volume: function (v) {
				var n = parseInt(v, 10);
				root.find('.am-vol-value').text(isNaN(n) ? '–' : n);
				if (!dragging && !isNaN(n)) { root.find('.am-vol-slider').val(n).trigger('am-fill'); }
			},

			app: function (v) {
				var name = appName(v);
				root.find('.am-app').prop('hidden', !name).text(name);
			},

			power_state: function (v) {
				var known = isId(root.attr('data-id-power_state'));
				if (!known) { return; } /* Apple TV 3 : pas de commande d'alimentation */
				var on = String(v) === '1', off = String(v) === '0';
				root.toggleClass('is-off', off).toggleClass('is-offline', !on && !off);
				root.find('.am-power-on').prop('hidden', !off || !isId(root.find('.am-power-on').attr('data-cmd')));
				root.find('.am-power-off').prop('hidden', !on || !isId(root.find('.am-power-off').attr('data-cmd')));
				root.find('.am-refresh').prop('hidden', on || off || !isId(root.find('.am-refresh').attr('data-cmd')));
				fitGroups();
			},

			repeat_state: function (v) {
				var b = root.find('.am-repeat');
				v = String(v);
				if (v !== '0' && v !== '1' && v !== '2') { b.prop('hidden', true); return; }
				b.prop('hidden', !isId(b.attr('data-c-all')));
				b.toggleClass('is-active', v !== '0').attr('data-state', v);
				b.find('[data-ic]').html(icon(v === '1' ? 'repeat_one' : 'repeat'));
				b.attr('aria-label', v === '0' ? 'Répétition : non' : (v === '2' ? 'Répétition : tout' : 'Répétition : piste'));
				fitGroups();
			},

			shuffle_state: function (v) {
				var b = root.find('.am-shuffle');
				v = String(v);
				if (v !== '0' && v !== '1') { b.prop('hidden', true); return; }
				b.prop('hidden', !isId(b.attr('data-c-on')));
				b.toggleClass('is-active', v === '1').attr('data-state', v);
				b.attr('aria-label', v === '1' ? 'Aléatoire : oui' : 'Aléatoire : non');
				fitGroups();
			}
		};

		function set(name, raw) {
			st[name] = plain(raw);
			if (apply[name]) { apply[name](st[name]); }
		}

		/* ---------- clics ---------- */
		root.on('click', '[data-cmd]', function () {
			run($(this).attr('data-cmd'));
		});
		root.on('click', '.am-repeat', function () {
			var s = String($(this).attr('data-state'));
			run($(this).attr(s === '0' ? 'data-c-all' : (s === '2' ? 'data-c-track' : 'data-c-off')));
		});
		root.on('click', '.am-shuffle', function () {
			run($(this).attr(String($(this).attr('data-state')) === '1' ? 'data-c-off' : 'data-c-on'));
		});

		/* ---------- curseur de volume (HomePod) ---------- */
		var slider = root.find('.am-vol-slider');
		slider.on('am-fill input', function () {
			var v = parseInt(this.value, 10) || 0;
			this.style.setProperty('--am-fill', v + '%');
		});
		slider.on('input', function () {
			dragging = true;
			root.find('.am-vol-value').text(parseInt(this.value, 10) || 0);
		});
		slider.on('change', function () {
			var id = $(this).attr('data-cmd-set');
			if (isId(id)) { jeedom.cmd.execute({ id: id, value: { slider: parseInt(this.value, 10) || 0 } }); }
			clearTimeout(dragTimer);
			dragTimer = setTimeout(function () { dragging = false; }, 800); /* laisse passer l'écho de l'appareil */
		});
		if (!isId(slider.attr('data-cmd-set'))) { slider.attr('hidden', ''); }

		/* ---------- lancement d'app (Apple TV 4) ---------- */
		var launch = root.find('.am-launch');
		if (launch.length) {
			var lid = launch.attr('data-cmd-set');
			if (!isId(lid) || launch.find('option').length <= 1) { launch.attr('hidden', ''); }
			launch.on('change', function () {
				if (this.value !== '') { jeedom.cmd.execute({ id: lid, value: { select: this.value } }); }
				this.selectedIndex = 0;
			});
		}

		/* ---------- valeurs initiales puis mises à jour en direct ---------- */
		$.each(apply, function (name) {
			var cid = root.attr('data-id-' + name);
			if (isId(cid)) {
				jeedom.cmd.addUpdateFunction(cid, function (o) { set(name, o.display_value); });
			}
		});
		/* les ids "info" non utilisés plus haut (genre, media_type, position) sont déjà dans apply */

		var order = ['artwork_url', 'title', 'artist', 'album', 'genre', 'media_type', 'position', 'play_human', 'play_state', 'volume', 'app', 'power_state', 'repeat_state', 'shuffle_state'];
		$.each(order, function (i, name) {
			var init = root.attr('data-val-' + name);
			if (init === undefined) { return; }
			if (!isId(root.attr('data-id-' + name))) { /* info absente de cet appareil */
				st[name] = '';
				return;
			}
			set(name, init);
		});
		updateSub();
		fitGroups();
		$(w).off('resize.am' + root.attr('data-eqLogic_id')).on('resize.am' + root.attr('data-eqLogic_id'), measureLater);
	}

	w.aTVremoteMobile = { init: init };
})(window, window.jQuery);
