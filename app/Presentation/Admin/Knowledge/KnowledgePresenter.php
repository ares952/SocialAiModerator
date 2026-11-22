<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Knowledge;

use App\Knowledge\InvalidKnowledge;
use App\Knowledge\KnowledgeBase;
use App\Knowledge\KnowledgeImport;
use App\Knowledge\SourceStatus;
use App\Knowledge\SourceType;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;
use Nette\Database\Row;
use Nette\Forms\Controls\CheckboxList;
use Nette\Http\FileUpload;


/**
 * Knowledge base: everyone reads, moderators write drafts (every save is a new version), admins
 * publish or withdraw a version.
 */
final class KnowledgePresenter extends AdminPresenter
{
	private const MaxImportBytes = 1024 * 1024;

	/** Version being edited or shown. */
	private ?Row $source = null;


	public function __construct(
		private readonly KnowledgeBase $knowledge,
		private readonly KnowledgeImport $import,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::View);
		$sources = $this->knowledge->overview();
		$this->template->setParameters([
			'sources' => $sources,
			'hasDrafts' => array_filter($sources, fn(Row $source) => $source->latest_status === SourceStatus::Draft->value) !== [],
			'canEdit' => $this->getUser()->isAllowed(Authorization::Knowledge, Authorization::Edit),
			'canPublish' => $this->getUser()->isAllowed(Authorization::Knowledge, Authorization::Manage),
		]);
	}


	public function actionAdd(): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::Edit);
	}


	public function actionImport(): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::Edit);
	}


	public function actionEdit(int $id): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::Edit);
		$this->source = $this->knowledge->find($id) ?? $this->error();
		$form = $this->getComponent('sourceForm');
		assert($form instanceof Form);
		$form->setDefaults([
			'key' => $this->source->key,
			'locale' => $this->source->locale,
			'type' => $this->source->source_type,
			'title' => $this->source->title,
			'content' => $this->source->content,
			'validFrom' => $this->source->valid_from,
			'validTo' => $this->source->valid_to,
		]);
		$this->template->setParameters([
			'source' => $this->source,
		]);
	}


	public function actionDetail(int $id): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::View);
		$this->source = $this->knowledge->find($id) ?? $this->error();
		$this->template->setParameters([
			'source' => $this->source,
			'versions' => $this->knowledge->versions($this->source->key, $this->source->locale),
			'canEdit' => $this->getUser()->isAllowed(Authorization::Knowledge, Authorization::Edit),
			'canPublish' => $this->getUser()->isAllowed(Authorization::Knowledge, Authorization::Manage),
		]);
	}


	public function handlePublish(int $version): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::Manage);
		try {
			$this->knowledge->publish($version, $this->operatorId());
		} catch (InvalidKnowledge $e) {
			$this->flashMessage($e->getMessage(), 'danger');
			$this->redirect('default');
		}

		$this->flashMessage('knowledge.published', 'success');
		$this->redirect('detail', [
			'id' => $version,
		]);
	}


	public function handleUnpublish(int $version): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::Manage);
		$this->knowledge->unpublish($version, $this->operatorId());
		$this->flashMessage('knowledge.unpublished', 'warning');
		$this->redirect('detail', [
			'id' => $version,
		]);
	}


	protected function createComponentSourceForm(): Form
	{
		$form = $this->forms->create();
		$key = $form->addText('key', 'knowledge.form.key')
			->setRequired('form.error.required')
			->addRule(Form::Pattern, 'knowledge.error.key', '[a-z0-9_]{1,100}')
			->setOption('description', 'knowledge.form.key-note');
		$locales = [];
		foreach ($this->knowledge->locales() as $locale) {
			$locales[$locale] = "knowledge.locale.$locale";
		}
		$locale = $form->addSelect('locale', 'knowledge.form.locale', $locales)
			->setRequired('form.error.required');
		$types = [];
		foreach (SourceType::cases() as $type) {
			$types[$type->value] = "knowledge.type.$type->value";
		}
		$form->addSelect('type', 'knowledge.form.type', $types)
			->setRequired('form.error.required');
		$form->addText('title', 'knowledge.form.title')
			->setRequired('form.error.required')
			->setMaxLength(200);
		$form->addTextArea('content', 'knowledge.form.content', rows: 12)
			->setRequired('form.error.required')
			->setOption('description', 'knowledge.form.content-note');
		$form->addDate('validFrom', 'knowledge.form.validFrom');
		$form->addDate('validTo', 'knowledge.form.validTo');
		$form->addSubmit('send', 'knowledge.form.save');

		// A new version keeps its key and locale.
		if ($this->getAction() === 'edit') {
			$key->setDisabled();
			$locale->setDisabled();
		}

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Knowledge, Authorization::Edit);
			$values = $form->getValues();
			try {
				$id = $this->knowledge->createDraft(
					$this->source !== null ? $this->source->key : (string) $values['key'],
					$this->source !== null ? $this->source->locale : (string) $values['locale'],
					SourceType::from((string) $values['type']),
					(string) $values['title'],
					(string) $values['content'],
					$values['validFrom'] instanceof \DateTimeImmutable ? $values['validFrom'] : null,
					$values['validTo'] instanceof \DateTimeImmutable ? $values['validTo'] : null,
					$this->operatorId(),
				);
			} catch (InvalidKnowledge $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage('knowledge.saved', 'success');
			$this->redirect('detail', [
				'id' => $id,
			]);
		};

		return $form;
	}


	/**
	 * Publishing of the checked drafts from the overview (the latest version of a key and locale).
	 */
	protected function createComponentPublishForm(): Form
	{
		$drafts = [];
		foreach ($this->knowledge->overview() as $source) {
			if ($source->latest_status === SourceStatus::Draft->value) {
				$drafts[$source->id] = $source->title;
			}
		}

		$form = $this->forms->create();
		$form->addCheckboxList('versions', null, $drafts)
			->setTranslator(null)
			->setRequired('knowledge.error.none-selected');
		$form->addSubmit('publish', 'knowledge.publish-selected');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Knowledge, Authorization::Manage);
			$versions = $form['versions'];
			assert($versions instanceof CheckboxList);
			$ids = array_map(intval(...), $versions->getValue());
			try {
				$this->knowledge->publishSelected($ids, $this->operatorId());
			} catch (InvalidKnowledge $e) {
				$form->addError($e->getMessage());

				return;
			}

			$this->flashMessage($this->translator->translate('knowledge.published-selected', count($ids)), 'success');
			$this->redirect('this');
		};

		return $form;
	}


	/**
	 * Upload of a knowledge file; it only creates drafts, an admin publishes them.
	 */
	protected function createComponentImportForm(): Form
	{
		$form = $this->forms->create();
		$form->addUpload('file', 'knowledge.import.file')
			->setRequired('form.error.required')
			->addRule(Form::MaxFileSize, 'knowledge.import.error.size', self::MaxImportBytes)
			->setHtmlAttribute('accept', '.json,application/json');
		$form->addSubmit('send', 'knowledge.import.submit');

		$form->onSuccess[] = function (Form $form): void {
			$this->requireAllowed(Authorization::Knowledge, Authorization::Edit);
			$file = $form->getValues()['file'];
			assert($file instanceof FileUpload);
			if (!$file->isOk()) {
				$form->addError('knowledge.import.error.upload');

				return;
			}
			try {
				$result = $this->import->import((string) $file->getContents(), $this->operatorId());
			} catch (InvalidKnowledge $e) {
				$reason = $this->translator->translate($e->getMessage());
				$form->addError($e->field === ''
					? $reason
					: $this->translator->translate('knowledge.import.error.at', 1, [
						'field' => $e->field,
						'reason' => $reason,
					]), translate: false);

				return;
			}

			$this->flashMessage($this->translator->translate('knowledge.import.done', $result['created'], [
				'skipped' => $result['skipped'],
			]), 'success');
			$this->redirect('default');
		};

		return $form;
	}
}
