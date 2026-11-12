(function () {
	'use strict';

	var formatter = new Intl.NumberFormat(document.documentElement.lang || undefined);

	function animateCounter(element) {
		if (element.dataset.animated === 'true') {
			return;
		}

		element.dataset.animated = 'true';

		var target = parseInt(element.dataset.logiprofCounter || '0', 10);
		var duration = parseInt(element.dataset.duration || '1400', 10);
		var startTime = null;

		function tick(timestamp) {
			if (!startTime) {
				startTime = timestamp;
			}

			var progress = Math.min((timestamp - startTime) / duration, 1);
			var eased = 1 - Math.pow(1 - progress, 3);
			var current = Math.round(target * eased);

			element.textContent = formatter.format(current);

			if (progress < 1) {
				window.requestAnimationFrame(tick);
			} else {
				element.textContent = formatter.format(target);
			}
		}

		window.requestAnimationFrame(tick);
	}

	function bootCounters() {
		var counters = document.querySelectorAll('[data-logiprof-counter]');

		if (!('IntersectionObserver' in window)) {
			counters.forEach(animateCounter);
			return;
		}

		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					return;
				}

				animateCounter(entry.target);
				observer.unobserve(entry.target);
			});
		}, {
			threshold: 0.35
		});

		counters.forEach(function (counter) {
			observer.observe(counter);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootCounters);
	} else {
		bootCounters();
	}
}());
