<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use Nette\Application\Responses\TextResponse;


/**
 * CSV download of the charts of a page (signal `export`, the link is in @chart.latte). The page
 * builds its charts for the current parameters after its action checked the permissions, so the
 * export contains exactly what the page shows.
 *
 * @phpstan-import-type Chart from Charts
 */
trait ChartExport
{
	protected Charts $charts;


	public function injectChartExport(Charts $charts): void
	{
		$this->charts = $charts;
	}


	/**
	 * @return array<string, Chart> exportable charts of the current page by name
	 */
	abstract protected function exportCharts(): array;


	public function handleExport(string $chart): void
	{
		$definition = $this->exportCharts()[$chart] ?? $this->error();
		$response = $this->getHttpResponse();
		$response->setContentType('text/csv', 'utf-8');
		$response->setHeader('Content-Disposition', sprintf('attachment; filename="%s-%s.csv"', $chart, gmdate('Ymd-His')));
		$this->sendResponse(new TextResponse($this->charts->csv($definition)));
	}
}
