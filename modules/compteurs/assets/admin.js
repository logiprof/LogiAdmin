(function () {
	'use strict';

	var SHADOWS = {
		none: 'none',
		soft: '0 14px 34px rgba(23, 33, 43, 0.08)',
		medium: '0 18px 42px rgba(23, 33, 43, 0.14)',
		strong: '0 22px 54px rgba(23, 33, 43, 0.22)'
	};
	var SIZES = { small: '32px', normal: '42px', large: '54px' };
	var TYPES = {
		account: 'accounts', accounts: 'accounts', compte: 'accounts', comptes: 'accounts', user: 'accounts', users: 'accounts',
		course: 'courses', courses: 'courses', cours: 'courses',
		lesson: 'lessons', lessons: 'lessons', lecon: 'lessons', lecons: 'lessons',
		question: 'questions', questions: 'questions'
	};
	var LAYOUTS = { grid: 'grid', grille: 'grid', row: 'row', ligne: 'row', horizontal: 'row', stack: 'stack', pile: 'stack', vertical: 'stack', inline: 'inline', compact: 'inline', compacte: 'inline' };

	function copyText(text, feedback) {
		function markCopied() {
			if (feedback) {
				feedback.textContent = 'Shortcode copié. Collez-le dans votre page.';
			}
		}

		if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(markCopied, fallback);
			return;
		}
		fallback();

		function fallback() {
			var helper = document.createElement('textarea');
			helper.value = text;
			helper.setAttribute('readonly', '');
			helper.style.position = 'fixed';
			helper.style.opacity = '0';
			document.body.appendChild(helper);
			helper.select();
			document.execCommand('copy');
			document.body.removeChild(helper);
			markCopied();
		}
	}

	function readAttr(shortcode, name) {
		var match = new RegExp('\\b' + name + '="([^"]*)"').exec(shortcode);
		return match ? match[1] : '';
	}

	function initAdmin() {
		var selectedInput = document.querySelector('#lac-selected-shortcode');
		var feedback = document.querySelector('.lac-copy-feedback');
		var layoutCards = document.querySelectorAll('.lac-layout-card');
		var designControls = document.querySelectorAll('[data-lac-design]');
		var palettes = document.querySelectorAll('[data-lac-palette]');
		var radiusOut = document.querySelector('[data-lac-radius-out]');
		var live = document.querySelector('[data-lac-live] .logiprof-counters');
		var currentBaseShortcode = selectedInput ? selectedInput.value : '';

		function withAttribute(shortcode, name, value) {
			return shortcode.replace(/\]$/, ' ' + name + '="' + value + '"]');
		}

		function design() {
			var values = {};
			designControls.forEach(function (control) {
				values[control.dataset.lacDesign] = control.type === 'checkbox' ? (control.checked ? 'yes' : 'no') : control.value;
			});
			return values;
		}

		function getDesignedShortcode(shortcode) {
			var values = design();
			return Object.keys(values).reduce(function (current, name) {
				return values[name] ? withAttribute(current, name, values[name]) : current;
			}, shortcode);
		}

		function refreshPreview() {
			if (!live) {
				return;
			}
			var values = design();
			var layout = LAYOUTS[readAttr(currentBaseShortcode, 'layout')] || 'grid';
			var style = readAttr(currentBaseShortcode, 'style') || 'card';
			var columns = Math.max(1, Math.min(4, parseInt(readAttr(currentBaseShortcode, 'columns') || '4', 10)));
			var items = (readAttr(currentBaseShortcode, 'items') || 'accounts,courses,lessons,questions').split(',').map(function (item) {
				return TYPES[item.trim()] || '';
			});

			live.className = 'logiprof-counters logiprof-counters--' + style + ' logiprof-counters--layout-' + layout;
			live.style.setProperty('--logiprof-counter-columns', columns);
			live.style.setProperty('--logiprof-counter-accent', values.accent);
			live.style.setProperty('--logiprof-counter-ink', values.text);
			live.style.setProperty('--logiprof-counter-muted', values.muted);
			live.style.setProperty('--logiprof-counter-surface', values.bg);
			live.style.setProperty('--logiprof-counter-border', values.border);
			live.style.setProperty('--logiprof-counter-radius', values.radius + 'px');
			live.style.setProperty('--logiprof-counter-shadow', SHADOWS[values.shadow] || SHADOWS.soft);
			live.style.setProperty('--logiprof-counter-value-size', SIZES[values.size] || SIZES.normal);

			live.querySelectorAll('.logiprof-counter').forEach(function (counter) {
				var type = (counter.className.match(/logiprof-counter--(accounts|courses|lessons|questions)/) || [])[1];
				counter.className = counter.className.replace(/logiprof-counter--(card|minimal|inline)\b/, 'logiprof-counter--' + style);
				counter.hidden = items.indexOf(type) === -1;
				counter.style.order = String(items.indexOf(type));
				counter.style.setProperty('--counter-local-accent', values.accent);
				var icon = counter.querySelector('.logiprof-counter__icon');
				if (icon) {
					icon.hidden = values.icons === 'no';
				}
			});

			if (radiusOut) {
				radiusOut.textContent = values.radius + ' px';
			}
		}

		function refreshShortcode() {
			if (selectedInput && currentBaseShortcode) {
				selectedInput.value = getDesignedShortcode(currentBaseShortcode);
			}
			refreshPreview();
		}

		layoutCards.forEach(function (card) {
			card.addEventListener('click', function () {
				layoutCards.forEach(function (item) {
					item.classList.remove('is-selected');
					item.setAttribute('aria-checked', 'false');
				});
				card.classList.add('is-selected');
				card.setAttribute('aria-checked', 'true');
				currentBaseShortcode = card.dataset.lacShortcode || '';
				refreshShortcode();
				if (feedback) {
					feedback.textContent = '';
				}
			});
		});

		palettes.forEach(function (button) {
			button.addEventListener('click', function () {
				var palette = JSON.parse(button.dataset.lacPalette || '{}');
				Object.keys(palette).forEach(function (name) {
					var control = document.querySelector('[data-lac-design="' + name + '"]');
					if (control) {
						control.value = palette[name];
					}
				});
				palettes.forEach(function (item) {
					item.classList.toggle('is-selected', item === button);
				});
				refreshShortcode();
			});
		});

		designControls.forEach(function (control) {
			control.addEventListener('input', refreshShortcode);
			control.addEventListener('change', refreshShortcode);
		});

		document.querySelectorAll('.lac-copy-button').forEach(function (button) {
			button.addEventListener('click', function () {
				var targetSelector = button.dataset.lacCopy;
				var target = targetSelector ? document.querySelector(targetSelector) : null;
				var text = button.dataset.lacShortcode ? getDesignedShortcode(button.dataset.lacShortcode) : (target ? target.value : '');

				if (!text) {
					return;
				}

				copyText(text, feedback);
				button.classList.add('is-copied');
				window.setTimeout(function () {
					button.classList.remove('is-copied');
				}, 1600);
			});
		});

		refreshShortcode();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAdmin);
	} else {
		initAdmin();
	}
}());
