<?php

declare(strict_types=1);

namespace Hyperf\ApiDocs\Swagger;

use Hyperf\ApiDocs\Annotation\ApiModelProperty;
use Hyperf\Database\Model\Model;
use Hyperf\Di\ReflectionManager;
use Hyperf\DTO\Annotation\Validation\In;
use Hyperf\DTO\Annotation\Validation\Required;
use Hyperf\DTO\ApiAnnotation;
use Hyperf\DTO\Scan\PropertyManager;
use Hyperf\Utils\ApplicationContext;
use Lengbin\Common\Annotation\ArrayType;
use Lengbin\Common\Annotation\EnumView;
use MabeEnum\Enum;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionProperty;
use stdClass;
use Throwable;

class SwaggerCommon
{
    public function getDefinitions(string $className): string
    {
        return '#/definitions/' . $this->getSimpleClassName($className);
    }

    protected function getDefinition(string $className): array
    {
        return SwaggerJson::$swagger['definitions'][$this->getSimpleClassName($className)] ?? [];
    }

    public function getSimpleClassName(string $className): string
    {
        return SwaggerJson::getSimpleClassName($className);
    }

    public function getParameterClassProperty(string $parameterClassName, string $in): array
    {
        $parameters = [];
        $rc = ReflectionManager::reflectClass($parameterClassName);
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) ?? [] as $reflectionProperty) {
            $property = [];
            $property['in'] = $in;
            $property['name'] = $reflectionProperty->getName();
            try {
                $property['default'] = $reflectionProperty->getValue(make($parameterClassName));
            } catch (Throwable) {
            }
            $phpType = $this->getTypeName($reflectionProperty);
            $property['type'] = $this->getType2SwaggerType($phpType);
            if (!in_array($phpType, ['integer', 'int', 'boolean', 'bool', 'string', 'double', 'float'])) {
                continue;
            }

            $apiModelProperty = ApiAnnotation::getProperty($parameterClassName, $reflectionProperty->getName(), ApiModelProperty::class);
            $apiModelProperty = $apiModelProperty ?: new ApiModelProperty();
            $requiredAnnotation = ApiAnnotation::getProperty($parameterClassName, $reflectionProperty->getName(), Required::class);
            /** @var In $inAnnotation */
            $inAnnotation = ApiAnnotation::getProperty($parameterClassName, $reflectionProperty->getName(), In::class);
            if ($apiModelProperty->hidden) {
                continue;
            }
            if (!empty($inAnnotation)) {
                $property['enum'] = $inAnnotation->getValue();
            }
            if ($apiModelProperty->required !== null) {
                $property['required'] = $apiModelProperty->required;
            }
            if ($requiredAnnotation !== null) {
                $property['required'] = true;
            }
            if ($apiModelProperty->example !== null) {
                $property['example'] = $apiModelProperty->example;
            }
            $property['description'] = $apiModelProperty->value ?? '';
            $parameters[] = $property;
        }
        return $parameters;
    }

    public function getTypeName(ReflectionProperty $rp): string
    {
        try {
            $type = $rp->getType()->getName();
        } catch (Throwable) {
            $type = 'string';
        }
        return $type;
    }

    public function getType2SwaggerType($phpType): string
    {
        return match ($phpType) {
            'int', 'integer' => 'integer',
            'boolean', 'bool' => 'boolean',
            'double', 'float' => 'number',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }

    public function getSimpleType2SwaggerType(string $phpType): ?string
    {
        return match ($phpType) {
            'int', 'integer' => 'integer',
            'boolean', 'bool' => 'boolean',
            'double', 'float' => 'number',
            'string', 'mixed' => 'string',
            default => null,
        };
    }

    protected function handleEnum($factory, $reflectionProperty, $propertyClass, $isPhp8, $property)
    {

        $phpType = $propertyClass->type;

        $flags = EnumView::ENUM_VALUE;
        if ($isPhp8) {
            $enumViews = $reflectionProperty->getAttributes(EnumView::class);
            if (!empty($enumViews)) {
                $flags = $enumViews[0]->newInstance()->flags;
            }
        }
        if (empty($enumViews)) {
            $docComment = $reflectionProperty->getDocComment();
            if ($docComment) {
                $enumViews = $factory->create($reflectionProperty->getDocComment())->getTagsByName('EnumView');
                if (!empty($enumViews)) {
                    $flags = EnumView::ENUM_ALL;
                }
            }
        }
        $property['type'] = 'array';
        if ($flags == EnumView::ENUM_ALL) {
            $type = 'object';
            $values = $propertyClass->className::getValues();
            $messages = $propertyClass->className::getMessages();
            $properties = [
                'value'   => [
                    'type' => $this->getType2SwaggerType(gettype(current($values))),
                    'enum' => $values,
                ],
                'message' => [
                    'type' => $this->getType2SwaggerType(gettype(current($messages))),
                    'enum' => $messages,
                ],
            ];
            if ($phpType === 'array') {
                $property['items']['type'] = $type;
                $property['items']['properties'] = $properties;
            } else {
                $property['type'] = $type;
                $property['properties'] = $properties;
            }
        } else {
            $values = [];
            if ($flags === EnumView::ENUM_VALUE) {
                $values = $propertyClass->className::getValues();
            }
            if ($flags === EnumView::ENUM_NAME) {
                $values = $propertyClass->className::getNames();
            }
            if ($flags === EnumView::ENUM_MESSAGE) {
                $values = $propertyClass->className::getMessages();
            }
            $type = $this->getType2SwaggerType(gettype(current($values)));
            if ($phpType === 'array') {
                $property['items']['enum'] = $values;
                $property['items']['type'] = $type;
            } else {
                $property['enum'] = $values;
                $property['type'] = $type;
            }
        }
        unset($property['$ref']);
        unset($property['items']['$ref']);
        return $property;
    }

    public function generateClass2schema(string $className): void
    {
        if (!ApplicationContext::getContainer()->has($className)) {
            $this->generateEmptySchema($className);
            return;
        }
        $obj = ApplicationContext::getContainer()->get($className);
        if ($obj instanceof Model) {
            //$this->getModelSchema($obj);
            $this->generateEmptySchema($className);
            return;
        }

        $schema = [
            'type'       => 'object',
            'properties' => [],
        ];
        $required = [];
        $rc = ReflectionManager::reflectClass($className);
        $factory = DocBlockFactory::createInstance();
        $isPhp8 = version_compare(PHP_VERSION, '8.0.0', '>');
        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) ?? [] as $reflectionProperty) {
            $fieldName = $reflectionProperty->getName();
            $propertyClass = PropertyManager::getProperty($className, $fieldName);

            $phpType = $propertyClass->type;
            $type = $this->getType2SwaggerType($phpType);
            $apiModelProperty = ApiAnnotation::getProperty($className, $fieldName, ApiModelProperty::class);
            $apiModelProperty = $apiModelProperty ?: new ApiModelProperty();
            /** @var In $inAnnotation */
            $inAnnotation = ApiAnnotation::getProperty($className, $reflectionProperty->getName(), In::class);

            if ($apiModelProperty->hidden) {
                continue;
            }

            $property = [];
            $isEnum = is_subclass_of($propertyClass->className, Enum::class);

            $property['type'] = $type;
            if (!empty($inAnnotation)) {
                $property['enum'] = $inAnnotation->getValue();
            }
            $property['description'] = $apiModelProperty->value ?? '';
            if ($apiModelProperty->required !== null) {
                $required[] = $fieldName;
            }
            if ($apiModelProperty->example !== null) {
                $property['example'] = $apiModelProperty->example;
            }
            if ($reflectionProperty->isPublic() && $reflectionProperty->isInitialized($obj)) {
                $property['default'] = $reflectionProperty->getValue($obj);
            }
            if ($phpType == 'array') {
                if ($isPhp8) {
                    $arrayTypes = $reflectionProperty->getAttributes(ArrayType::class);
                    if (!empty($arrayTypes)) {
                        $arrayType = $arrayTypes[0]->newInstance();
                        $propertyClass->className = $arrayType->type;
                        if ($arrayType->className) {
                            $propertyClass->className = $arrayType->className;
                        }
                    }
                }
                if ($propertyClass->className == null) {
                    $property['items'] = (object)[];
                } else {
                    if ($propertyClass->isSimpleType) {
                        $property['items']['type'] = $this->getType2SwaggerType($propertyClass->className);
                    } else {
                        if (!$isEnum) {
                            $this->generateClass2schema($propertyClass->className);
                            $property['items']['$ref'] = $this->getDefinitions($propertyClass->className);
                        }
                    }
                }
            }
            if ($type == 'object') {
                $property['items'] = (object)[];
            }
            if (!$propertyClass->isSimpleType && $phpType != 'array' && class_exists($propertyClass->className) && !$isEnum) {
                $this->generateClass2schema($propertyClass->className);
                if (!empty($property['description'])) {
                    $definition = $this->getDefinition($propertyClass->className);
                    $definition['description'] = $property['description'];
                    SwaggerJson::$swagger['definitions'][$this->getSimpleClassName($propertyClass->className)] = $definition;
                }
                $property = ['$ref' => $this->getDefinitions($propertyClass->className)];
            }

            if ($isEnum) {
                $property = $this->handleEnum($factory, $reflectionProperty, $propertyClass, $isPhp8, $property);
            }

            $schema['properties'][$fieldName] = $property;
        }

        if (empty($schema['properties'])) {
            $schema['properties'] = new stdClass();
        }
        if (!empty($required)) {
            $schema['required'] = $required;
        }
        SwaggerJson::$swagger['definitions'][$this->getSimpleClassName($className)] = $schema;
    }

    public function isSimpleType($type): bool
    {
        return $type == 'string' || $type == 'boolean' || $type == 'bool' || $type == 'integer' || $type == 'int' || $type == 'double' || $type == 'float' || $type == 'array' || $type == 'object';
    }

    protected function generateEmptySchema(string $className)
    {
        $schema = [
            'type'       => 'object',
            'properties' => new stdClass(),
        ];
        SwaggerJson::$swagger['definitions'][$this->getSimpleClassName($className)] = $schema;
    }

    protected function getModelSchema(object $model)
    {
        //$reflect = new ReflectionObject($model);
        //$docComment = $reflect->getDocComment();
    }
}
