<?php

declare(strict_types=1);

namespace Hyperf\ApiDocs\Annotation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ApiQueryProperty extends ApiModelProperty
{

}
