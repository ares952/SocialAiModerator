// Dashboard charts (Chart.js 4). Every <canvas data-chart="{...}"> gets a chart from the definition
// built on the server by App\Presentation\Admin\Dashboard\DashboardData (labels already translated).
// A dataset color is a key of the CSS variables --sa-chart-<color> in admin.css, 'current' is the
// text color around the canvas. Colors are read when the chart is drawn, so charts are redrawn when
// theme.js switches the light/dark theme. Without JavaScript the table under each chart remains.
(() => {
	'use strict';

	if (!window.Chart) {
		return;
	}

	const root = document.documentElement;
	const charts = new Map();
	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
	const probe = document.createElement('canvas').getContext('2d');

	// Any CSS color (hex, rgb(), named) as [r, g, b], via the canvas color parser.
	const channels = (color) => {
		probe.fillStyle = '#000';
		probe.fillStyle = color;
		const value = probe.fillStyle;
		if (value.startsWith('#')) {
			return [1, 3, 5].map((start) => parseInt(value.slice(start, start + 2), 16));
		}
		return value.match(/[\d.]+/g).slice(0, 3).map(Number);
	};

	const rgba = (color, alpha = 1) => `rgba(${channels(color).join(', ')}, ${alpha})`;

	const variable = (name) => getComputedStyle(root).getPropertyValue(name).trim();

	const color = (canvas, key) => (key === 'current' ? getComputedStyle(canvas).color : variable(`--sa-chart-${key}`));

	const options = (canvas, spec) => {
		const text = variable('--cui-body-color');
		const muted = variable('--cui-secondary-color');
		const grid = variable('--cui-border-color-translucent');
		const number = new Intl.NumberFormat(root.lang || undefined, { maximumFractionDigits: 1 });
		const unit = spec.unit ? ` ${spec.unit}` : '';
		const round = spec.type === 'doughnut';
		const value = () => ({
			stacked: Boolean(spec.stacked),
			beginAtZero: true,
			grid: { color: grid },
			border: { display: false },
			ticks: {
				color: muted,
				precision: spec.unit ? undefined : 0,
				callback: (tick) => `${number.format(tick)}${unit}`,
			},
		});
		const category = {
			stacked: Boolean(spec.stacked),
			grid: { display: false },
			border: { display: false },
			ticks: { color: muted, autoSkip: true, maxRotation: 0 },
		};

		return {
			responsive: true,
			maintainAspectRatio: false,
			animation: reducedMotion.matches ? false : undefined,
			indexAxis: spec.horizontal ? 'y' : 'x',
			interaction: { mode: round ? 'nearest' : 'index', intersect: round },
			layout: { padding: spec.sparkline ? { top: 4, bottom: 2 } : 0 },
			scales: round || spec.sparkline
				? { x: { display: false }, y: { display: false, beginAtZero: true } }
				: (spec.horizontal ? { x: value(), y: category } : { x: category, y: value() }),
			plugins: {
				legend: {
					display: !spec.sparkline && (round || spec.datasets.length > 1),
					position: 'bottom',
					labels: { color: text, boxWidth: 12, boxHeight: 12, usePointStyle: true },
				},
				tooltip: {
					callbacks: {
						label: (context) => {
							const label = round ? context.label : context.dataset.label;
							const raw = context.parsed && typeof context.parsed === 'object'
								? context.parsed[spec.horizontal ? 'x' : 'y']
								: context.parsed;
							return raw === null || raw === undefined ? label : `${label}: ${number.format(raw)}${unit}`;
						},
					},
				},
			},
		};
	};

	const datasets = (canvas, spec) => spec.datasets.map((set) => {
		const line = (set.type || spec.type) === 'line';
		if (spec.type === 'doughnut') {
			return {
				label: set.label,
				data: set.data,
				backgroundColor: (set.colors || []).map((key) => rgba(color(canvas, key))),
				borderColor: variable('--cui-body-bg'),
				borderWidth: 2,
			};
		}
		const base = color(canvas, set.color);
		return {
			label: set.label,
			data: set.data,
			type: set.type,
			order: line ? 0 : 1,
			backgroundColor: line ? rgba(base, spec.sparkline ? 0.2 : 0.15) : rgba(base, 0.85),
			borderColor: rgba(base),
			borderWidth: line ? 2 : 0,
			borderRadius: line ? 0 : 3,
			pointRadius: spec.sparkline ? 0 : 2,
			pointHoverRadius: 4,
			pointHitRadius: 8,
			tension: 0.3,
			fill: Boolean(spec.sparkline),
			spanGaps: true,
		};
	});

	const draw = (canvas) => {
		charts.get(canvas)?.destroy();
		const spec = JSON.parse(canvas.dataset.chart);
		charts.set(canvas, new window.Chart(canvas, {
			type: spec.type,
			data: { labels: spec.labels, datasets: datasets(canvas, spec) },
			options: options(canvas, spec),
		}));
	};

	const drawAll = () => document.querySelectorAll('canvas[data-chart]').forEach(draw);

	drawAll();
	document.addEventListener('socialai:theme', drawAll);
})();
