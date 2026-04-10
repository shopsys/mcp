<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\EventSubscriber;

use Override;
use Psr\Log\LoggerInterface;
use Shopsys\McpBundle\Component\Database\Schema\McpSchemaFileGenerator;
use Shopsys\MigrationBundle\Event\DatabaseSchemaMigratedEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

class DatabaseSchemaMigratedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        protected readonly McpSchemaFileGenerator $mcpSchemaFileGenerator,
        #[Autowire(service: 'monolog.logger.mcp')]
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function generateSchemaFile(DatabaseSchemaMigratedEvent $databaseSchemaMigratedEvent): void
    {
        try {
            $this->mcpSchemaFileGenerator->generateSchemaFile();
        } catch (Throwable $throwable) {
            $this->logger->error('MCP schema generation after database migration failed.', [
                'schema_file_path' => $this->mcpSchemaFileGenerator->getSchemaFilePath(),
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            DatabaseSchemaMigratedEvent::class => 'generateSchemaFile',
        ];
    }
}
