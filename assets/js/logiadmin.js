/* LogiAdmin : petits comportements communs à tous les écrans (copie, confirmation). */
(function () {
	'use strict';

	var ui = window.LogiAdminUI || { copied: 'Copié', copyErr: 'Copie impossible' };
	var toast = null;
	var timer = null;

	function showToast(message) {
		if (!toast) {
			toast = document.createElement('div');
			toast.className = 'lad-toast';
			toast.setAttribute('role', 'status');
			toast.setAttribute('aria-live', 'polite');
			document.body.appendChild(toast);
		}
		toast.textContent = message;
		toast.classList.add('is-visible');
		window.clearTimeout(timer);
		timer = window.setTimeout(function () {
			toast.classList.remove('is-visible');
		}, 1800);
	}

	function legacyCopy(field, text) {
		var helper = field;
		if (!helper) {
			helper = document.createElement('textarea');
			helper.value = text;
			helper.style.position = 'fixed';
			helper.style.opacity = '0';
			document.body.appendChild(helper);
		}
		helper.select();
		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}
		if (!field) {
			document.body.removeChild(helper);
		}
		showToast(ok ? ui.copied : ui.copyErr);
	}

	function copy(text, field) {
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function () {
				showToast(ui.copied);
			}, function () {
				legacyCopy(field, text);
			});
			return;
		}
		legacyCopy(field, text);
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest ? event.target.closest('[data-logiadmin-copy]') : null;
		if (trigger) {
			var field = document.querySelector(trigger.getAttribute('data-logiadmin-copy'));
			if (field) {
				copy(field.value, field);
			}
			return;
		}

		var confirmLink = event.target.closest ? event.target.closest('a[data-logiadmin-confirm], button[data-logiadmin-confirm]') : null;
		if (confirmLink && !window.confirm(confirmLink.getAttribute('data-logiadmin-confirm'))) {
			event.preventDefault();
		}
	});

	// Un clic dans un champ de shortcode sélectionne tout le texte.
	document.addEventListener('focusin', function (event) {
		if (event.target.classList && event.target.classList.contains('lad-copy-input')) {
			event.target.select();
		}
	});
}());
