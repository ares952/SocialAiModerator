<?php

declare(strict_types=1);

namespace App\Audit;


enum AuditAction: string
{
	case Login = 'login';
	case OperatorCreate = 'operator.create';
	case OperatorUpdate = 'operator.update';
	case OperatorPassword = 'operator.password';
}
