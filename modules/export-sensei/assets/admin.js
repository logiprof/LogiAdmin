(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
			return;
		}
		document.addEventListener('DOMContentLoaded', fn);
	}

	function post(data) {
		var body = new URLSearchParams();
		Object.keys(data).forEach(function (key) {
			if (Array.isArray(data[key])) {
				data[key].forEach(function (value) {
					body.append(key, value);
				});
				return;
			}
			body.append(key, data[key]);
		});
		return fetch(LogiSenseiExport.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	ready(function () {
		var search = document.getElementById('logisensei-search');
		var category = document.getElementById('logisensei-category');
		var selectAll = document.getElementById('logisensei-select-all');
		var exportButton = document.getElementById('logisensei-export-button');
		var progress = document.querySelector('.logisensei-progress');
		var progressBar = document.querySelector('.logisensei-progress-bar span');
		var progressLabel = document.getElementById('logisensei-progress-label');
		var progressTitle = document.getElementById('logisensei-progress-title');
		var completeNotice = document.getElementById('logisensei-complete-notice');
		var downloadLink = document.getElementById('logisensei-download-link');
		var exportButtonLabel = exportButton ? exportButton.textContent : '';

		function rows() {
			return Array.prototype.slice.call(document.querySelectorAll('.logisensei-course-table tbody tr'));
		}

		function filterRows() {
			var term = search ? search.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase() : '';
			var cat = category ? category.value : '';
			rows().forEach(function (row) {
				var matchesSearch = !term || row.dataset.title.indexOf(term) !== -1;
				var matchesCategory = !cat || row.dataset.category.split(' ').indexOf(cat) !== -1;
				row.hidden = !(matchesSearch && matchesCategory);
			});
		}

		if (search) {
			search.addEventListener('input', filterRows);
		}
		if (category) {
			category.addEventListener('change', filterRows);
		}
		var counter = document.getElementById('logisensei-selected-count');

		function visibleChecks() {
			return rows().filter(function (row) {
				return !row.hidden;
			}).map(function (row) {
				return row.querySelector('input[type="checkbox"]');
			}).filter(Boolean);
		}

		function refreshSelection() {
			var checked = document.querySelectorAll('.logisensei-course-check:checked').length;
			var labels = LogiSenseiExport.labels;
			if (counter) {
				counter.textContent = checked === 0 ? labels.none : (checked === 1 ? labels.one : labels.many.replace('%d', checked));
			}
			rows().forEach(function (row) {
				var box = row.querySelector('input[type="checkbox"]');
				row.classList.toggle('is-selected', !!(box && box.checked));
			});
			if (selectAll) {
				var visible = visibleChecks();
				var allOn = visible.length > 0 && visible.every(function (box) { return box.checked; });
				selectAll.textContent = allOn ? selectAll.dataset.labelNone : selectAll.dataset.labelAll;
			}
		}

		if (selectAll) {
			selectAll.addEventListener('click', function () {
				var visible = visibleChecks();
				var allOn = visible.length > 0 && visible.every(function (box) { return box.checked; });
				visible.forEach(function (box) {
					box.checked = !allOn;
				});
				refreshSelection();
			});
		}

		document.addEventListener('change', function (event) {
			if (event.target && event.target.classList && event.target.classList.contains('logisensei-course-check')) {
				refreshSelection();
			}
		});
		if (search) {
			search.addEventListener('input', refreshSelection);
		}
		if (category) {
			category.addEventListener('change', refreshSelection);
		}
		refreshSelection();

		function setProgress(percent, message) {
			if (progress) {
				progress.hidden = false;
			}
			if (progressBar) {
				progressBar.style.width = percent + '%';
			}
			if (progressLabel) {
				progressLabel.textContent = message;
			}
		}

		function setExporting(isExporting) {
			if (progress) {
				progress.classList.toggle('is-exporting', isExporting);
				progress.classList.toggle('is-complete', false);
			}
			if (progressTitle) {
				progressTitle.textContent = isExporting ? LogiSenseiExport.labels.working : exportButtonLabel;
			}
			if (completeNotice) {
				completeNotice.hidden = true;
			}
			if (exportButton) {
				exportButton.disabled = isExporting;
				exportButton.textContent = isExporting ? LogiSenseiExport.labels.working : exportButtonLabel;
			}
		}

		function setComplete(message) {
			if (progress) {
				progress.classList.remove('is-exporting');
				progress.classList.add('is-complete');
			}
			if (progressTitle) {
				progressTitle.textContent = LogiSenseiExport.labels.finished;
			}
			if (completeNotice) {
				completeNotice.hidden = false;
			}
			if (exportButton) {
				exportButton.disabled = false;
				exportButton.textContent = exportButtonLabel;
			}
			setProgress(100, message || LogiSenseiExport.labels.finished);
		}

		function runStep(jobId) {
			post({
				action: 'logisensei_export_step',
				nonce: LogiSenseiExport.nonce,
				job_id: jobId
			}).then(function (result) {
				if (!result || !result.success) {
					throw new Error(result && result.data ? result.data.message : LogiSenseiExport.labels.exportError);
				}
				setProgress(result.data.progress, result.data.message);
				if (result.data.done) {
					setComplete(result.data.message);
					if (downloadLink) {
						downloadLink.innerHTML = '<a class="button button-primary" href="' + result.data.download_url + '">Telecharger le ZIP</a>';
					}
					return;
				}
				window.setTimeout(function () {
					runStep(jobId);
				}, 350);
			}).catch(function (error) {
				setProgress(100, error.message);
				if (progress) {
					progress.classList.remove('is-exporting');
				}
				if (exportButton) {
					exportButton.disabled = false;
					exportButton.textContent = exportButtonLabel;
				}
			});
		}

		if (exportButton) {
			exportButton.addEventListener('click', function () {
				var checked = Array.prototype.slice.call(document.querySelectorAll('.logisensei-course-check:checked'));
				var ids = checked.map(function (checkbox) {
					return checkbox.value;
				});

				if (!ids.length) {
					window.alert(LogiSenseiExport.labels.selectCourse);
					return;
				}

				setExporting(true);
				if (downloadLink) {
					downloadLink.innerHTML = '';
				}
				setProgress(5, 'Analyse des cours...');

				var data = {
					action: 'logisensei_start_export',
					nonce: LogiSenseiExport.nonce
				};
				ids.forEach(function (id) {
					data['course_ids[]'] = data['course_ids[]'] || [];
					data['course_ids[]'].push(id);
				});

				post(data).then(function (result) {
					if (!result || !result.success) {
						throw new Error(result && result.data ? result.data.message : LogiSenseiExport.labels.exportError);
					}
					setProgress(10, result.data.message);
					runStep(result.data.job_id);
				}).catch(function (error) {
					setProgress(100, error.message);
					if (progress) {
						progress.classList.remove('is-exporting');
					}
					exportButton.disabled = false;
					exportButton.textContent = exportButtonLabel;
				});
			});
		}
	});
}());
