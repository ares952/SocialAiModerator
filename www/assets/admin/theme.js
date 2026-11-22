// Light/dark theme of the administration (CoreUI color modes). Loaded in <head> so the
// page renders in the right theme without flashing. The choice is a per-browser preference;
// "auto" follows the operating system.
(() => {
	'use strict';

	const storageKey = 'socialai.theme';
	const themes = ['light', 'dark', 'auto'];
	const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

	const stored = () => {
		try {
			return localStorage.getItem(storageKey);
		} catch {
			return null; // storage blocked (private mode, disabled site data)
		}
	};

	const store = (theme) => {
		try {
			localStorage.setItem(storageKey, theme);
		} catch {
			// the choice then lasts only for this page
		}
	};

	const preferred = () => (themes.includes(stored()) ? stored() : 'auto');

	const apply = (theme) => {
		const resolved = theme === 'auto' ? (darkQuery.matches ? 'dark' : 'light') : theme;
		document.documentElement.setAttribute('data-coreui-theme', resolved);

		document.querySelectorAll('[data-theme-value]').forEach((button) => {
			const active = button.dataset.themeValue === theme;
			button.classList.toggle('active', active);
			button.setAttribute('aria-pressed', String(active));
		});
		document.querySelectorAll('[data-theme-icon]').forEach((icon) => {
			icon.setAttribute('href', icon.getAttribute('href').replace(/#.*$/, `#theme-${theme}`));
		});
		// Charts read their colors from CSS variables and redraw on this event.
		document.dispatchEvent(new CustomEvent('socialai:theme', { detail: resolved }));
	};

	apply(preferred());

	darkQuery.addEventListener('change', () => {
		if (preferred() === 'auto') {
			apply('auto');
		}
	});

	document.addEventListener('DOMContentLoaded', () => {
		apply(preferred());
		document.querySelectorAll('[data-theme-value]').forEach((button) => {
			button.addEventListener('click', () => {
				store(button.dataset.themeValue);
				apply(button.dataset.themeValue);
			});
		});
	});
})();
