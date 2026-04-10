<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\Component\Database\Schema;

use Doctrine\DBAL\Schema\Name\Parsers;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Shopsys\McpAttributes\Attribute\AsMcpTable;

class AllowedDatabaseTablesProvider
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SchemaNameNormalizer $schemaNameNormalizer,
    ) {
    }

    /**
     * @return array<string>
     */
    public function getAllowedTableNames(): array
    {
        return array_keys($this->getAllAllowedClassMetadataByTableNames());
    }

    /**
     * @return array<string, \Doctrine\ORM\Mapping\ClassMetadata>
     */
    public function getAllAllowedClassMetadataByTableNames(): array
    {
        return $this->getAllowedClassMetadataByTableNamesFiltered(null);
    }

    /**
     * @param array<string> $requestedTableNames
     * @return array<string, \Doctrine\ORM\Mapping\ClassMetadata>
     */
    public function getAllowedClassMetadataByTableNames(array $requestedTableNames): array
    {
        return $this->getAllowedClassMetadataByTableNamesFiltered($requestedTableNames);
    }

    /**
     * @param array<string>|null $requestedTableNames
     * @return array<string, \Doctrine\ORM\Mapping\ClassMetadata>
     */
    protected function getAllowedClassMetadataByTableNamesFiltered(?array $requestedTableNames): array
    {
        $allowedClassMetadataByTableNames = [];

        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $classMetadata) {
            if (!$classMetadata instanceof ClassMetadata) {
                continue;
            }

            if (!$this->isTableExposed($classMetadata)) {
                continue;
            }

            $tableName = $this->normalizeTableName($classMetadata->getTableName());

            if ($requestedTableNames !== null && !in_array($tableName, $requestedTableNames, true)) {
                continue;
            }

            $allowedClassMetadataByTableNames[$tableName] = $classMetadata;
        }

        ksort($allowedClassMetadataByTableNames);

        return $allowedClassMetadataByTableNames;
    }

    protected function normalizeTableName(string $tableName): string
    {
        return $this->schemaNameNormalizer->normalizeTableName(
            Parsers::getOptionallyQualifiedNameParser()->parse($tableName),
        );
    }

    protected function isTableExposed(ClassMetadata $classMetadata): bool
    {
        $asMcpTableAttributes = $classMetadata->getReflectionClass()->getAttributes(AsMcpTable::class);

        return $asMcpTableAttributes !== [] && $asMcpTableAttributes[0]->newInstance()->exposed;
    }
}
