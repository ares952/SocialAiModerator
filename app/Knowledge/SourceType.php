<?php

declare(strict_types=1);

namespace App\Knowledge;


enum SourceType: string
{
	case Faq = 'faq';
	case Pricing = 'pricing';
	case Product = 'product';
	case Terms = 'terms';
	case Other = 'other';
}
