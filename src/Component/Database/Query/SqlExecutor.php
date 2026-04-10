<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\Component\Database\Query;

use Doctrine\DBAL\Connection;

class SqlExecutor
{
    public function __construct(
        protected readonly Connection $mcpConnection,
        protected readonly QueryResultMcpNormalizer $queryResultMcpNormalizer,
        protected readonly SqlQueryValidator $sqlQueryValidator,
        protected readonly int $statementTimeoutMilliseconds,
    ) {
    }

    public function execute(string $sql): SqlExecutionResult
    {
        $sqlQueryValidationResult = $this->sqlQueryValidator->validate($sql);

        if (!$sqlQueryValidationResult->isValid || $sqlQueryValidationResult->singleStatementSql === null) {
            return SqlExecutionResult::createInvalid($sqlQueryValidationResult->errorMessage ?? 'SQL query is invalid.');
        }

        $singleStatementSql = $sqlQueryValidationResult->singleStatementSql;

        $startedAt = microtime(true);
        $originalStatementTimeout = (string)$this->mcpConnection->fetchOne("SELECT current_setting('statement_timeout')");

        try {
            $this->mcpConnection->executeStatement(sprintf(
                'SET statement_timeout TO %s',
                $this->mcpConnection->quote(sprintf('%dms', $this->statementTimeoutMilliseconds)),
            ));

            $rows = $this->queryResultMcpNormalizer->normalizeRows(
                $this->mcpConnection->fetchAllAssociative($singleStatementSql),
            );
        } finally {
            $this->mcpConnection->executeStatement(sprintf(
                'SET statement_timeout TO %s',
                $this->mcpConnection->quote($originalStatementTimeout),
            ));
        }

        return SqlExecutionResult::createValid([
            'columnNames' => $rows !== [] ? array_keys(reset($rows)) : [],
            'rows' => $rows,
            'rowCount' => count($rows),
            'durationMs' => round((microtime(true) - $startedAt) * 1000, 3),
        ]);
    }
}
