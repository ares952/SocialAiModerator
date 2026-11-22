<?php

declare(strict_types=1);

namespace App\Audit;


enum AuditAction: string
{
	case Login = 'login';
	case OperatorCreate = 'operator.create';
	case OperatorUpdate = 'operator.update';
	case OperatorPassword = 'operator.password';
	case PolicyUpdate = 'policy.update';
	case AutomationPause = 'automation.pause';
	case AutomationResume = 'automation.resume';
	case KnowledgeCreate = 'knowledge.create';
	case KnowledgePublish = 'knowledge.publish';
	case KnowledgeUnpublish = 'knowledge.unpublish';
	case KnowledgeImport = 'knowledge.import';
	case SampleCreate = 'evaluation.sample.create';
	case SampleUpdate = 'evaluation.sample.update';
	case SampleDelete = 'evaluation.sample.delete';
	case EvaluationRun = 'evaluation.run';
	case AccountCreate = 'account.create';
	case AccountUpdate = 'account.update';
	case TokenReplace = 'account.token.replace';
	case EventContentView = 'event.content.view';
	case EventDecisionRequest = 'event.decision.request';
	case RetentionUpdate = 'retention.update';
	case ActionApprove = 'action.approve';
	case ActionReject = 'action.reject';
	case ActionRequest = 'action.request';
}
