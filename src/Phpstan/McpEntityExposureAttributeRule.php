<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\Phpstan;

use Override;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

class McpEntityExposureAttributeRule implements Rule
{
    protected const string APP_NAMESPACE = 'App\\';
    protected const string SHOPSYS_NAMESPACE = 'Shopsys\\';
    protected const TABLE_ATTRIBUTE_CLASS = 'Shopsys\\McpAttributes\\Attribute\\AsMcpTable';
    protected const COLUMN_ATTRIBUTE_CLASS = 'Shopsys\\McpAttributes\\Attribute\\AsMcpColumn';
    protected const ORM_ENTITY_ATTRIBUTE_CLASS = 'Doctrine\\ORM\\Mapping\\Entity';
    protected const ORM_COLUMN_ATTRIBUTE_CLASS = 'Doctrine\\ORM\\Mapping\\Column';
    protected const ORM_EMBEDDED_ATTRIBUTE_CLASS = 'Doctrine\\ORM\\Mapping\\Embedded';
    protected const ORM_ONE_TO_ONE_ATTRIBUTE_CLASS = 'Doctrine\\ORM\\Mapping\\OneToOne';
    protected const ORM_MANY_TO_ONE_ATTRIBUTE_CLASS = 'Doctrine\\ORM\\Mapping\\ManyToOne';
    protected const PREZENT_TRANSLATABLE_ATTRIBUTE_CLASS = 'Prezent\\Doctrine\\Translatable\\Attribute\\Translatable';

    #[Override]
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    #[Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $scope->getClassReflection();

        if ($classReflection === null || !$this->isCheckedNamespace($classReflection->getName())) {
            return [];
        }

        if ($this->isTestPath($scope->getFile())) {
            return [];
        }

        $className = $classReflection->getName();

        if (!$this->isCheckedNamespace($className) || !$this->isOrmEntity($classReflection->getNativeReflection()->getAttributes())) {
            return [];
        }

        $errors = [];
        $tableAttribute = $this->getAttributeByClassName(
            $classReflection->getNativeReflection()->getAttributes(),
            static::TABLE_ATTRIBUTE_CLASS,
        );

        if ($tableAttribute === null) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Entity "%s" must declare #[AsMcpTable(exposed: bool)].',
                    $className,
                ))->identifier('shopsys.mcpEntityExposure')->build(),
            ];
        }

        if (!$tableAttribute->newInstance()->exposed) {
            return [];
        }

        $classLevelColumnExposureByFieldNames = $this->getClassLevelColumnExposureByFieldNames($classReflection->getNativeReflection());

        foreach ($classReflection->getNativeReflection()->getProperties() as $property) {
            if (!$this->requiresMcpColumnAttribute($property)) {
                continue;
            }

            if ($this->hasMcpColumnExposure($property, $classLevelColumnExposureByFieldNames)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Mapped property "%s::$%s" must declare #[AsMcpColumn(exposed: bool)] because the entity is exposed via MCP.',
                $className,
                $property->getName(),
            ))->identifier('shopsys.mcpColumnExposure')->build();
        }

        return $errors;
    }

    protected function isCheckedNamespace(string $className): bool
    {
        return str_starts_with($className, static::APP_NAMESPACE)
            || str_starts_with($className, static::SHOPSYS_NAMESPACE);
    }

    protected function isTestPath(string $filePath): bool
    {
        return preg_match('#/(tests|Tests)/#', $filePath) === 1;
    }

    /**
     * @return array<string, bool>
     */
    protected function getClassLevelColumnExposureByFieldNames(ReflectionClass $reflectionClass): array
    {
        $classLevelColumnExposureByFieldNames = [];

        foreach ($reflectionClass->getAttributes(static::COLUMN_ATTRIBUTE_CLASS) as $attribute) {
            $asMcpColumn = $attribute->newInstance();

            if ($asMcpColumn->fieldName === null) {
                continue;
            }

            $classLevelColumnExposureByFieldNames[$asMcpColumn->fieldName] = $asMcpColumn->exposed;
        }

        return $classLevelColumnExposureByFieldNames;
    }

    /**
     * @param array<\ReflectionAttribute<object>> $attributes
     */
    protected function isOrmEntity(array $attributes): bool
    {
        return $this->getAttributeByClassName($attributes, static::ORM_ENTITY_ATTRIBUTE_CLASS) !== null;
    }

    protected function requiresMcpColumnAttribute(ReflectionProperty $property): bool
    {
        $attributes = $property->getAttributes();

        if ($this->getAttributeByClassName($attributes, static::ORM_COLUMN_ATTRIBUTE_CLASS) !== null) {
            return true;
        }

        if ($this->getAttributeByClassName($attributes, static::ORM_EMBEDDED_ATTRIBUTE_CLASS) !== null) {
            return true;
        }

        if ($this->getAttributeByClassName($attributes, static::ORM_MANY_TO_ONE_ATTRIBUTE_CLASS) !== null) {
            return true;
        }

        if ($this->getAttributeByClassName($attributes, static::PREZENT_TRANSLATABLE_ATTRIBUTE_CLASS) !== null) {
            return true;
        }

        $oneToOneAttribute = $this->getAttributeByClassName($attributes, static::ORM_ONE_TO_ONE_ATTRIBUTE_CLASS);

        if ($oneToOneAttribute === null) {
            return false;
        }

        $oneToOneMapping = $oneToOneAttribute->newInstance();

        return !property_exists($oneToOneMapping, 'mappedBy') || $oneToOneMapping->mappedBy === null;
    }

    /**
     * @param array<string, bool> $classLevelColumnExposureByFieldNames
     */
    protected function hasMcpColumnExposure(
        ReflectionProperty $property,
        array $classLevelColumnExposureByFieldNames,
    ): bool {
        if ($this->getAttributeByClassName($property->getAttributes(), static::COLUMN_ATTRIBUTE_CLASS) !== null) {
            return true;
        }

        return array_key_exists($property->getName(), $classLevelColumnExposureByFieldNames);
    }

    /**
     * @param array<\ReflectionAttribute<object>> $attributes
     * @return \ReflectionAttribute<object>|null
     */
    protected function getAttributeByClassName(array $attributes, string $attributeClassName): ?ReflectionAttribute
    {
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === $attributeClassName) {
                return $attribute;
            }
        }

        return null;
    }
}
