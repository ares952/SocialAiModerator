// Small behaviours of the administration that work without it as well.
// A checkbox with data-check-all="<name>" checks or unchecks every checkbox of that name in its form.
(() => {
	'use strict';

	document.addEventListener('change', (event) => {
		const toggle = event.target;
		if (!(toggle instanceof HTMLInputElement) || !toggle.dataset.checkAll || !toggle.form) {
			return;
		}
		for (const box of toggle.form.querySelectorAll('input[type="checkbox"]')) {
			if (box.name === toggle.dataset.checkAll && !box.disabled) {
				box.checked = toggle.checked;
			}
		}
	});
})();
