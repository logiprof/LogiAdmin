(function ($) {
	'use strict';

	var data = window.logiprofAmmMenu || {};
	var hideTimer = null;
	var $panel = null;
	var $activeRow = null;
	var $topMenu = null;

	function normalizeUrl(url) {
		if (!url) {
			return '';
		}

		var link = document.createElement('a');
		link.href = String(url || '').replace(/&amp;/g, '&');
		return link.href;
	}

	function sameUrl(left, right) {
		left = normalizeUrl(left);
		right = normalizeUrl(right);

		return left && right && left === right;
	}

	function cancelHide() {
		if (hideTimer) {
			window.clearTimeout(hideTimer);
			hideTimer = null;
		}
	}

	function hidePanel() {
		cancelHide();

		if ($panel) {
			$panel.hide().empty();
		}

		if ($activeRow) {
			$activeRow.removeClass('logiprof-amm-active');
			$activeRow = null;
		}

		if ($topMenu) {
			$topMenu.removeClass('logiprof-amm-menu-open opensub');
		}
	}

	function scheduleHide() {
		cancelHide();
		hideTimer = window.setTimeout(hidePanel, 180);
	}

	function createPanel() {
		if ($panel) {
			return $panel;
		}

		$panel = $('<div />', {
			'class': 'logiprof-amm-flyout-panel',
			'role': 'menu'
		}).appendTo(document.body);

		$panel.on('mouseenter focusin', cancelHide);
		$panel.on('mouseleave focusout', scheduleHide);

		return $panel;
	}

	function renderPanel(item) {
		var $list;

		createPanel().empty();

		$('<div />', {
			'class': 'logiprof-amm-flyout-title',
			text: item.title
		}).appendTo($panel);

		$list = $('<ul />').appendTo($panel);

		item.children.forEach(function (child) {
			var $link = $('<a />', {
				href: child.url,
				text: child.title,
				role: 'menuitem'
			});

			$('<li />').append($link).appendTo($list);
		});
	}

	function positionPanel($row) {
		var rowRect = $row.get(0).getBoundingClientRect();
		var panelWidth = $panel.outerWidth();
		var panelHeight = $panel.outerHeight();
		var top = rowRect.top;
		var left = rowRect.right + 1;
		var minTop = 32;
		var maxTop = Math.max(minTop, window.innerHeight - panelHeight - 12);

		if (top > maxTop) {
			top = maxTop;
		}

		if (top < minTop) {
			top = minTop;
		}

		if (left + panelWidth > window.innerWidth - 12) {
			left = Math.max(36, rowRect.left - panelWidth - 1);
		}

		$panel.css({
			left: left,
			top: top
		});
	}

	function showPanel(item, $row) {
		if (!item.children || !item.children.length) {
			hidePanel();
			return;
		}

		cancelHide();
		renderPanel(item);

		if ($activeRow) {
			$activeRow.removeClass('logiprof-amm-active');
		}

		$activeRow = $row.addClass('logiprof-amm-active');
		$topMenu.addClass('logiprof-amm-menu-open opensub');

		$panel.css({
			display: 'block',
			visibility: 'hidden',
			left: -9999,
			top: -9999
		});

		positionPanel($row);
		$panel.css('visibility', 'visible');
	}

	function findRows($submenu) {
		var homeUrl = normalizeUrl(data.homeUrl);
		var settingsUrl = normalizeUrl(data.settingsUrl);

		return $submenu.children('li').not('.wp-submenu-head').filter(function () {
			var href = normalizeUrl($(this).children('a').attr('href'));
			return href && href !== homeUrl && href !== settingsUrl;
		});
	}

	function enhanceMenu() {
		var $submenu;
		var $rows;

		if (!Array.isArray(data.items) || !data.items.length || !data.menuId) {
			return;
		}

		$topMenu = $('#' + data.menuId);
		$submenu = $topMenu.children('.wp-submenu');

		if (!$topMenu.length || !$submenu.length) {
			return;
		}

		$rows = findRows($submenu);

		data.items.forEach(function (item, index) {
			var $row = $();
			var itemUrl = normalizeUrl(item.url);

			$rows.each(function () {
				var $candidate = $(this);
				var href = normalizeUrl($candidate.children('a').attr('href'));

				if (!$candidate.data('logiprofAmmBound') && sameUrl(href, itemUrl)) {
					$row = $candidate;
					return false;
				}

				return true;
			});

			if (!$row.length) {
				$row = $rows.eq(index);
			}

			if (!$row.length) {
				return;
			}

			$row
				.addClass('logiprof-amm-parent')
				.data('logiprofAmmBound', true)
				.data('logiprofAmmItem', item);

			if (item.children && item.children.length) {
				$row.addClass('logiprof-amm-has-children');
				$row.children('a').attr('aria-haspopup', 'true').append(
					$('<span />', {
						'class': 'logiprof-amm-child-indicator',
						'aria-hidden': 'true',
						text: '>'
					})
				);
			}
		});

		$topMenu.on('mouseenter focusin', cancelHide);
		$topMenu.on('mouseleave focusout', scheduleHide);

		$submenu.on('mouseenter focusin', 'li.logiprof-amm-has-children', function () {
			var $row = $(this);
			showPanel($row.data('logiprofAmmItem'), $row);
		});

		$(document).on('keydown', function (event) {
			if (event.key === 'Escape') {
				hidePanel();
			}
		});

		$(window).on('resize scroll', hidePanel);
	}

	$(enhanceMenu);
})(jQuery);
