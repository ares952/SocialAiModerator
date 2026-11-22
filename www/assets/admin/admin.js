// Small behaviours of the administration that work without it as well.
// A checkbox with data-check-all="<name>" checks or unchecks every checkbox of that name in its form.
// A button with data-sidebar-toggle="<id>" shows or hides the CoreUI sidebar of that id.
(() => {
	'use strict';

	document.addEventListener('click', (event) => {
		const toggle = event.target instanceof Element ? event.target.closest('[data-sidebar-toggle]') : null;
		const sidebar = toggle ? document.getElementById(toggle.dataset.sidebarToggle) : null;
		if (sidebar && window.coreui) {
			window.coreui.Sidebar.getOrCreateInstance(sidebar).toggle();
		}
	});

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
