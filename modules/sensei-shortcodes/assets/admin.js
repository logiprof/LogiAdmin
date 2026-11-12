(function () {
	'use strict';

	function quote(value) {
		return String(value).replace(/"/g, "'").replace(/\]/g, ')').replace(/\[/g, '(');
	}

	function isShown(element) {
		while (element && element !== document.body) {
			if (element.hidden) {
				return false;
			}
			element = element.parentElement;
		}
		return true;
	}

	function bindGenerator() {
		var output = document.getElementById('lssc-output');
		var typeField = document.getElementById('lssc-type');
		var help = document.getElementById('lssc-type-help');
		var categoryField = document.getElementById('lssc-category');
		var copyButton = document.getElementById('lssc-copy');
		var feedback = document.getElementById('lssc-feedback');

		if (!output || !typeField) {
			return;
		}

		function updateVisibility() {
			var type = typeField.value;
			document.querySelectorAll('[data-lssc-show]').forEach(function (block) {
				block.hidden = block.getAttribute('data-lssc-show').split(' ').indexOf(type) === -1;
			});
			document.querySelectorAll('[data-lssc-show-if]').forEach(function (block) {
				var toggle = document.getElementById(block.getAttribute('data-lssc-show-if'));
				block.hidden = !toggle || !toggle.checked;
			});
			if (help) {
				var option = typeField.options[typeField.selectedIndex];
				help.textContent = option ? (option.getAttribute('data-help') || '') : '';
			}
		}

		function updateCategoryField() {
			if (!categoryField) {
				return;
			}
			var values = [];
			document.querySelectorAll('.lssc-category-choice:checked').forEach(function (field) {
				values.push(field.value);
			});
			categoryField.value = values.join(',');
		}

		function updateShortcode() {
			var parts = ['logiprof_sensei'];
			updateVisibility();
			updateCategoryField();

			document.querySelectorAll('[data-shortcode-field]').forEach(function (field) {
				if (field.type !== 'hidden' && !isShown(field)) {
					return;
				}
				if (field === categoryField && !isShown(categoryField.parentElement)) {
					return;
				}
				var key = field.getAttribute('data-shortcode-field');
				var value = field.type === 'checkbox'
					? (field.checked ? field.getAttribute('data-checked-value') : field.getAttribute('data-unchecked-value'))
					: field.value.trim();

				if (value !== '' && value !== null) {
					parts.push(key + '="' + quote(value) + '"');
				}
			});

			output.value = '[' + parts.join(' ') + ']';
			if (feedback) {
				feedback.textContent = '';
			}
			return output.value;
		}

		function copyShortcode() {
			var text = updateShortcode();
			function done() {
				if (feedback) {
					feedback.textContent = 'Copié !';
				}
			}
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done, legacy);
			} else {
				legacy();
			}
			function legacy() {
				output.select();
				output.setSelectionRange(0, 99999);
				document.execCommand('copy');
				done();
			}
		}

		document.querySelectorAll('[data-shortcode-field], .lssc-category-choice, #lssc-show-filters, #lssc-reveal').forEach(function (field) {
			field.addEventListener('input', updateShortcode);
			field.addEventListener('change', updateShortcode);
		});

		if (copyButton) {
			copyButton.addEventListener('click', copyShortcode);
		}

		updateShortcode();
		window.logiprofSenseiUpdateShortcode = updateShortcode;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindGenerator);
	} else {
		bindGenerator();
	}
}());
