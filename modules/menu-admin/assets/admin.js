(function ($) {
	'use strict';

	function renumberRows($rows) {
		$rows.each(function (index) {
			$(this).find('.logiprof-amm-order-input').val(index + 1);
		});
	}

	$(function () {
		var $tableBody = $('.logiprof-amm-table tbody');

		if (!$tableBody.length || !$tableBody.find('[data-menu-row]').length) {
			return;
		}

		if ($.fn.sortable) {
			$tableBody.sortable({
				axis: 'y',
				handle: '.logiprof-amm-drag-handle',
				items: 'tr[data-menu-row]',
				update: function () {
					renumberRows($tableBody.find('tr[data-menu-row]'));
				}
			});
		}

		$('.logiprof-amm-renumber').on('click', function () {
			renumberRows($tableBody.find('tr[data-menu-row]'));
		});

		// La colonne « Après enregistrement » suit la case cochée, sans attendre l'enregistrement.
		$tableBody.on('change', '.logiprof-amm-visible', function () {
			var $status = $(this).closest('tr').find('.logiprof-amm-status');
			var main = this.checked;
			$status.toggleClass('is-main', main).toggleClass('is-relocated', !main)
				.text(main ? $status.data('labelMain') : $status.data('labelRelocated'));
		});
	});
})(jQuery);
