<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Knowledge;

use App\Knowledge\InvalidKnowledge;
use App\Knowledge\KnowledgeBase;
use App\Knowledge\SourceType;
use App\Operator\Authorization;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\UI\Form;
use Nette\Database\Row;


/**
 * Knowledge base: everyone reads, moderators write drafts (every save is a new version), admins
 * publish or withdraw a version.
 */
final class KnowledgePresenter extends AdminPresenter
{
	/** Version being edited or shown. */
	private ?Row $source = null;


	public function __construct(
		private readonly KnowledgeBase $knowledge,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->requireAllowed(Authorization::Knowledge, Authorization::View);
		$this->template->setParameters([
			'sources' => $this->knowledge->overview(),
			'canEdit' => $this->getUser()->isAllowed(Authorization::Knowledge, Authorization::Edit),
		]);
	}


	public function actionAdd(): void
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
		$this->knowledge->publish($version, $this->operatorId());
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
}
