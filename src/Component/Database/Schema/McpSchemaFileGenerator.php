<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\Component\Database\Schema;

class McpSchemaFileGenerator
{
    public function __construct(
        protected readonly ExposedSchemaProvider $exposedSchemaProvider,
        protected readonly string $schemaFilePath,
    ) {
    }

    public function getSchemaFilePath(): string
    {
        return $this->schemaFilePath;
    }

    public function generateSchemaFile(): bool
    {
        $generatedSchemaJson = $this->generateSchemaJson();
        $existingSchemaJson = is_file($this->schemaFilePath) ? file_get_contents($this->schemaFilePath) : false;

        if ($existingSchemaJson === $generatedSchemaJson) {
            return false;
        }

        file_put_contents($this->schemaFilePath, $generatedSchemaJson);

        return true;
    }

    public function generateSchemaJson(): string
    {
        return $this->exposedSchemaProvider->generateExposedSchemaJson();
    }
}
