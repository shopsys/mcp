<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\Component\Database\Query;

use Shopsys\McpBundle\Component\Database\Query\Exception\SqlQueryParsingException;
use Shopsys\McpBundle\Component\Database\Schema\ExposedSchemaProvider;

class SqlQueryValidator
{
    public const string ERROR_EMPTY_QUERY = 'SQL query must not be empty.';
    public const string ERROR_ONLY_SELECT_SUPPORTED = 'Only SELECT statements are supported.';
    public const string ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT = 'The SQL query uses an unsupported read/write construct.';
    public const string ERROR_WILDCARD_SELECT_NOT_SUPPORTED = 'Wildcard column selection is not supported. List exposed columns explicitly.';
    public const string ERROR_TABLE_NOT_EXPOSED = 'The SQL query references a table that is not exposed through MCP.';
    public const string ERROR_COLUMN_NOT_EXPOSED = 'The SQL query references a column that is not exposed through MCP.';
    public const string ERROR_AMBIGUOUS_UNQUALIFIED_COLUMN = 'The SQL query references an ambiguous unqualified column. Qualify the column with a table or relation alias.';
    public const string ERROR_UNKNOWN_RELATION_ALIAS = 'The SQL query references an unknown table or relation alias.';
    public const string ERROR_LIMIT_REQUIRED_FORMAT = 'The SQL query must include a top-level LIMIT <= %d.';

    protected const string NODE_TAG_A_STAR = 'A_Star';
    protected const string NODE_TAG_COLUMN_REF = 'ColumnRef';
    protected const string NODE_TAG_COMMON_TABLE_EXPR = 'CommonTableExpr';
    protected const string NODE_TAG_DELETE_STMT = 'DeleteStmt';
    protected const string NODE_TAG_FUNC_CALL = 'FuncCall';
    protected const string NODE_TAG_INSERT_STMT = 'InsertStmt';
    protected const string NODE_TAG_INTO_CLAUSE = 'IntoClause';
    protected const string NODE_TAG_JOIN_EXPR = 'JoinExpr';
    protected const string NODE_TAG_LOCKING_CLAUSE = 'LockingClause';
    protected const string NODE_TAG_MERGE_STMT = 'MergeStmt';
    protected const string NODE_TAG_RANGE_FUNCTION = 'RangeFunction';
    protected const string NODE_TAG_RANGE_SUBSELECT = 'RangeSubselect';
    protected const string NODE_TAG_RANGE_TABLE_FUNC = 'RangeTableFunc';
    protected const string NODE_TAG_RANGE_VAR = 'RangeVar';
    protected const string NODE_TAG_RES_TARGET = 'ResTarget';
    protected const string NODE_TAG_SELECT_STMT = 'SelectStmt';
    protected const string NODE_TAG_STRING = 'String';
    protected const string NODE_TAG_UPDATE_STMT = 'UpdateStmt';

    protected const array UNSUPPORTED_NODE_TAGS = [
        self::NODE_TAG_DELETE_STMT,
        self::NODE_TAG_INSERT_STMT,
        self::NODE_TAG_INTO_CLAUSE,
        self::NODE_TAG_LOCKING_CLAUSE,
        self::NODE_TAG_MERGE_STMT,
        self::NODE_TAG_RANGE_FUNCTION,
        self::NODE_TAG_RANGE_TABLE_FUNC,
        self::NODE_TAG_UPDATE_STMT,
    ];

    protected const array UNSAFE_FUNCTION_NAMES = [
        'dblink',
        'lo_export',
        'lo_import',
        'pg_cancel_backend',
        'pg_terminate_backend',
        'nextval',
        'set_config',
        'setval',
    ];

    public function __construct(
        protected readonly ExposedSchemaProvider $exposedSchemaProvider,
        protected readonly PostgresQueryParser $postgresQueryParser,
        protected readonly int $maxReturnedRows,
    ) {
    }

    public function validate(string $sql): SqlQueryValidationResult
    {
        if (trim($sql) === '') {
            return SqlQueryValidationResult::createInvalid(self::ERROR_EMPTY_QUERY);
        }

        try {
            $parsedSqlQuery = $this->postgresQueryParser->parseSingleStatement($sql);
        } catch (SqlQueryParsingException $sqlQueryParsingException) {
            return SqlQueryValidationResult::createInvalid($sqlQueryParsingException->getMessage());
        }

        if ($this->getWrappedNodeTag($parsedSqlQuery->statement) !== self::NODE_TAG_SELECT_STMT) {
            return SqlQueryValidationResult::createInvalid(self::ERROR_ONLY_SELECT_SUPPORTED);
        }

        $validationErrorMessage = $this->validateSelectStatement(
            $parsedSqlQuery->statement[self::NODE_TAG_SELECT_STMT],
            $this->exposedSchemaProvider->getAllowedColumnsSetIndexedByTableNames(),
            true,
        );

        if ($validationErrorMessage !== null) {
            return SqlQueryValidationResult::createInvalid($validationErrorMessage);
        }

        return SqlQueryValidationResult::createValid($parsedSqlQuery->singleStatementSql);
    }

    /**
     * @param array<string, mixed> $selectStatement
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function validateSelectStatement(
        array $selectStatement,
        array $allowedColumnsSetIndexedByTableNames,
        bool $requireLimit = false,
    ): ?string {
        if (array_key_exists('intoClause', $selectStatement)) {
            return self::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT;
        }

        if ($requireLimit && !$this->hasAllowedLimit($selectStatement)) {
            return $this->getLimitRequiredErrorMessage();
        }

        $cteRelationsIndexedByNames = [];
        $outputColumnNamesSet = $this->getOutputColumnsSet($selectStatement);

        foreach ($this->getCteDefinitions($selectStatement) as $cteName => $cteDefinition) {
            $cteQuery = $cteDefinition['query'];

            if ($this->getWrappedNodeTag($cteQuery) !== self::NODE_TAG_SELECT_STMT) {
                return self::ERROR_ONLY_SELECT_SUPPORTED;
            }

            $validationErrorMessage = $this->validateSelectStatement($cteQuery[self::NODE_TAG_SELECT_STMT], $allowedColumnsSetIndexedByTableNames);

            if ($validationErrorMessage !== null) {
                return $validationErrorMessage;
            }

            $cteRelationsIndexedByNames[$cteName] = [
                'type' => 'cte',
                'columnNamesSet' => $this->getOutputColumnsSet($cteQuery[self::NODE_TAG_SELECT_STMT], $cteDefinition['columnNamesSet']),
            ];
        }

        $relationsIndexedByAliases = $cteRelationsIndexedByNames;

        foreach ($selectStatement['fromClause'] ?? [] as $fromNode) {
            $validationErrorMessage = $this->collectRelationsFromNode(
                $fromNode,
                $relationsIndexedByAliases,
                $cteRelationsIndexedByNames,
                $allowedColumnsSetIndexedByTableNames,
            );

            if ($validationErrorMessage !== null) {
                return $validationErrorMessage;
            }
        }

        foreach ($selectStatement as $key => $value) {
            if ($key === 'withClause') {
                continue;
            }

            $allowedOutputColumnNamesSet = $key === 'targetList' ? null : $outputColumnNamesSet;

            $validationErrorMessage = $this->validateNode(
                $value,
                $relationsIndexedByAliases,
                $allowedColumnsSetIndexedByTableNames,
                $allowedOutputColumnNamesSet,
            );

            if ($validationErrorMessage !== null) {
                return $validationErrorMessage;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     * @param array<string, bool>|null $outputColumnNamesSet
     */
    protected function validateNode(
        mixed $node,
        array $relationsIndexedByAliases,
        array $allowedColumnsSetIndexedByTableNames,
        ?array $outputColumnNamesSet = null,
    ): ?string {
        if (!is_array($node)) {
            return null;
        }

        if (array_is_list($node)) {
            foreach ($node as $childNode) {
                $validationErrorMessage = $this->validateNode(
                    $childNode,
                    $relationsIndexedByAliases,
                    $allowedColumnsSetIndexedByTableNames,
                    $outputColumnNamesSet,
                );

                if ($validationErrorMessage !== null) {
                    return $validationErrorMessage;
                }
            }

            return null;
        }

        $wrappedNodeTag = $this->getWrappedNodeTag($node);

        if ($wrappedNodeTag !== null) {
            if (in_array($wrappedNodeTag, self::UNSUPPORTED_NODE_TAGS, true)) {
                return self::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT;
            }

            if ($wrappedNodeTag === self::NODE_TAG_SELECT_STMT) {
                return $this->validateSelectStatement($node[self::NODE_TAG_SELECT_STMT], $allowedColumnsSetIndexedByTableNames);
            }

            if ($wrappedNodeTag === self::NODE_TAG_COLUMN_REF) {
                return $this->validateColumnReference(
                    $node[self::NODE_TAG_COLUMN_REF],
                    $relationsIndexedByAliases,
                    $allowedColumnsSetIndexedByTableNames,
                    $outputColumnNamesSet,
                );
            }

            if ($wrappedNodeTag === self::NODE_TAG_FUNC_CALL && $this->isUnsafeFunctionCall($node[self::NODE_TAG_FUNC_CALL])) {
                return self::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT;
            }

            return $this->validateNode(
                $node[$wrappedNodeTag],
                $relationsIndexedByAliases,
                $allowedColumnsSetIndexedByTableNames,
                $outputColumnNamesSet,
            );
        }

        foreach ($node as $childNode) {
            $validationErrorMessage = $this->validateNode(
                $childNode,
                $relationsIndexedByAliases,
                $allowedColumnsSetIndexedByTableNames,
                $outputColumnNamesSet,
            );

            if ($validationErrorMessage !== null) {
                return $validationErrorMessage;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array{type: 'cte', columnNamesSet: array<string, bool>|null}> $cteRelationsIndexedByNames
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function collectRelationsFromNode(
        mixed $fromNode,
        array &$relationsIndexedByAliases,
        array $cteRelationsIndexedByNames,
        array $allowedColumnsSetIndexedByTableNames,
    ): ?string {
        if (!is_array($fromNode)) {
            return null;
        }

        $wrappedNodeTag = $this->getWrappedNodeTag($fromNode);

        if ($wrappedNodeTag === self::NODE_TAG_JOIN_EXPR) {
            return $this->collectRelationsFromJoinExpr(
                $fromNode[self::NODE_TAG_JOIN_EXPR] ?? [],
                $relationsIndexedByAliases,
                $cteRelationsIndexedByNames,
                $allowedColumnsSetIndexedByTableNames,
            );
        }

        if ($wrappedNodeTag === self::NODE_TAG_RANGE_SUBSELECT) {
            return $this->collectRelationsFromRangeSubselect(
                $fromNode[self::NODE_TAG_RANGE_SUBSELECT] ?? [],
                $relationsIndexedByAliases,
            );
        }

        if (in_array($wrappedNodeTag, [self::NODE_TAG_RANGE_FUNCTION, self::NODE_TAG_RANGE_TABLE_FUNC], true)) {
            return self::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT;
        }

        if ($wrappedNodeTag !== self::NODE_TAG_RANGE_VAR) {
            return null;
        }

        return $this->collectRelationsFromRangeVar(
            $fromNode[self::NODE_TAG_RANGE_VAR] ?? [],
            $relationsIndexedByAliases,
            $cteRelationsIndexedByNames,
            $allowedColumnsSetIndexedByTableNames,
        );
    }

    /**
     * @param array<string, mixed> $joinExpr
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array{type: 'cte', columnNamesSet: array<string, bool>|null}> $cteRelationsIndexedByNames
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function collectRelationsFromJoinExpr(
        array $joinExpr,
        array &$relationsIndexedByAliases,
        array $cteRelationsIndexedByNames,
        array $allowedColumnsSetIndexedByTableNames,
    ): ?string {
        $validationErrorMessage = $this->collectRelationsFromNode(
            $joinExpr['larg'] ?? null,
            $relationsIndexedByAliases,
            $cteRelationsIndexedByNames,
            $allowedColumnsSetIndexedByTableNames,
        );

        if ($validationErrorMessage !== null) {
            return $validationErrorMessage;
        }

        return $this->collectRelationsFromNode(
            $joinExpr['rarg'] ?? null,
            $relationsIndexedByAliases,
            $cteRelationsIndexedByNames,
            $allowedColumnsSetIndexedByTableNames,
        );
    }

    /**
     * @param array<string, mixed> $rangeSubselect
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     */
    protected function collectRelationsFromRangeSubselect(
        array $rangeSubselect,
        array &$relationsIndexedByAliases,
    ): ?string {
        $aliasName = $this->getAliasName($rangeSubselect['alias'] ?? null);

        if ($aliasName === null) {
            return null;
        }

        $relationsIndexedByAliases[$aliasName] = [
            'type' => 'derived',
            'columnNamesSet' => $this->getRangeSubselectOutputColumnsSet($rangeSubselect),
        ];

        return null;
    }

    /**
     * @param array<string, mixed> $rangeVar
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array{type: 'cte', columnNamesSet: array<string, bool>|null}> $cteRelationsIndexedByNames
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function collectRelationsFromRangeVar(
        array $rangeVar,
        array &$relationsIndexedByAliases,
        array $cteRelationsIndexedByNames,
        array $allowedColumnsSetIndexedByTableNames,
    ): ?string {
        $tableName = strtolower((string)($rangeVar['relname'] ?? ''));
        $schemaName = strtolower((string)($rangeVar['schemaname'] ?? ''));

        if ($tableName === '') {
            return self::ERROR_TABLE_NOT_EXPOSED;
        }

        $aliasName = $this->getAliasName($rangeVar['alias'] ?? null) ?? $tableName;

        if (array_key_exists($tableName, $cteRelationsIndexedByNames)) {
            $relationsIndexedByAliases[$aliasName] = $cteRelationsIndexedByNames[$tableName];

            return null;
        }

        if ($schemaName !== '' && $schemaName !== 'public') {
            return self::ERROR_TABLE_NOT_EXPOSED;
        }

        if (!array_key_exists($tableName, $allowedColumnsSetIndexedByTableNames)) {
            return self::ERROR_TABLE_NOT_EXPOSED;
        }

        $this->storeTableRelation($relationsIndexedByAliases, $tableName, $aliasName);

        return null;
    }

    /**
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     */
    protected function storeTableRelation(
        array &$relationsIndexedByAliases,
        string $tableName,
        string $aliasName,
    ): void {
        $tableRelation = ['type' => 'table', 'tableName' => $tableName];
        $relationsIndexedByAliases[$tableName] = $tableRelation;
        $relationsIndexedByAliases[$aliasName] = $tableRelation;
    }

    /**
     * @param array<string, mixed> $columnReference
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     * @param array<string, bool>|null $outputColumnNamesSet
     */
    protected function validateColumnReference(
        array $columnReference,
        array $relationsIndexedByAliases,
        array $allowedColumnsSetIndexedByTableNames,
        ?array $outputColumnNamesSet = null,
    ): ?string {
        $columnReferenceParts = $this->getColumnReferenceParts($columnReference);

        if ($columnReferenceParts === null) {
            return null;
        }

        if ($columnReferenceParts === []) {
            return self::ERROR_WILDCARD_SELECT_NOT_SUPPORTED;
        }

        return match (count($columnReferenceParts)) {
            1 => $this->validateUnqualifiedColumnReference(
                $columnReferenceParts[0],
                $relationsIndexedByAliases,
                $allowedColumnsSetIndexedByTableNames,
                $outputColumnNamesSet,
            ),
            2 => $this->validateQualifiedColumnReference(
                $columnReferenceParts[0],
                $columnReferenceParts[1],
                $relationsIndexedByAliases,
                $allowedColumnsSetIndexedByTableNames,
            ),
            3 => $this->validateSchemaQualifiedColumnReference($columnReferenceParts, $allowedColumnsSetIndexedByTableNames),
            default => null,
        };
    }

    /**
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     * @param array<string, bool>|null $outputColumnNamesSet
     */
    protected function validateUnqualifiedColumnReference(
        string $columnName,
        array $relationsIndexedByAliases,
        array $allowedColumnsSetIndexedByTableNames,
        ?array $outputColumnNamesSet,
    ): ?string {
        if (array_key_exists($columnName, $outputColumnNamesSet ?? [])) {
            return null;
        }

        return $this->resolveUnqualifiedColumnReferenceError(
            $columnName,
            $relationsIndexedByAliases,
            $allowedColumnsSetIndexedByTableNames,
        );
    }

    /**
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function validateQualifiedColumnReference(
        string $relationName,
        string $columnName,
        array $relationsIndexedByAliases,
        array $allowedColumnsSetIndexedByTableNames,
    ): ?string {
        $relation = $relationsIndexedByAliases[$relationName] ?? null;

        if ($relation === null) {
            return self::ERROR_UNKNOWN_RELATION_ALIAS;
        }

        if ($relation['type'] === 'table' && isset($relation['tableName'])) {
            return $this->isAllowedTableColumnReference($relation['tableName'], $columnName, $allowedColumnsSetIndexedByTableNames)
                ? null
                : self::ERROR_COLUMN_NOT_EXPOSED;
        }

        return $this->isAllowedDerivedColumnReference($relationName, $columnName, $relationsIndexedByAliases)
            ? null
            : self::ERROR_COLUMN_NOT_EXPOSED;
    }

    /**
     * @param array<int, string> $columnReferenceParts
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function validateSchemaQualifiedColumnReference(
        array $columnReferenceParts,
        array $allowedColumnsSetIndexedByTableNames,
    ): ?string {
        if ($columnReferenceParts[0] !== 'public') {
            return null;
        }

        return $this->isAllowedTableColumnReference($columnReferenceParts[1], $columnReferenceParts[2], $allowedColumnsSetIndexedByTableNames)
            ? null
            : self::ERROR_COLUMN_NOT_EXPOSED;
    }

    /**
     * @param array<string, mixed> $columnReference
     * @return array<int, string>|null
     */
    protected function getColumnReferenceParts(array $columnReference): ?array
    {
        $parts = [];

        foreach ($columnReference['fields'] ?? [] as $field) {
            if (!is_array($field)) {
                return null;
            }

            $wrappedNodeTag = $this->getWrappedNodeTag($field);

            if ($wrappedNodeTag === self::NODE_TAG_A_STAR) {
                return [];
            }

            if ($wrappedNodeTag !== self::NODE_TAG_STRING) {
                return null;
            }

            $stringValue = strtolower((string)($field[self::NODE_TAG_STRING]['sval'] ?? ''));

            if ($stringValue === '') {
                return null;
            }

            $parts[] = $stringValue;
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $funcCall
     */
    protected function isUnsafeFunctionCall(array $funcCall): bool
    {
        $functionName = $this->getNormalizedFunctionName($funcCall);

        if ($functionName === '') {
            return false;
        }

        if (in_array($functionName, self::UNSAFE_FUNCTION_NAMES, true)) {
            return true;
        }

        return str_starts_with($functionName, 'pg_advisory_');
    }

    /**
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function isAllowedTableColumnReference(
        string $tableName,
        string $columnName,
        array $allowedColumnsSetIndexedByTableNames,
    ): bool {
        return array_key_exists($columnName, $allowedColumnsSetIndexedByTableNames[$tableName] ?? []);
    }

    /**
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     */
    protected function isAllowedDerivedColumnReference(
        string $relationName,
        string $columnName,
        array $relationsIndexedByAliases,
    ): bool {
        $relation = $relationsIndexedByAliases[$relationName] ?? null;

        return $relation !== null
            && $relation['type'] !== 'table'
            && array_key_exists($columnName, $relation['columnNamesSet'] ?? []);
    }

    /**
     * @param array<string, mixed> $selectStatement
     * @return array<string, array{query: array<string, mixed>, columnNamesSet: array<string, bool>|null}>
     */
    protected function getCteDefinitions(array $selectStatement): array
    {
        $cteDefinitions = [];

        $cteNodes = $selectStatement['withClause']['ctes']
            ?? $selectStatement['withClause']['WithClause']['ctes']
            ?? [];

        foreach ($cteNodes as $cteNode) {
            if (!is_array($cteNode) || $this->getWrappedNodeTag($cteNode) !== self::NODE_TAG_COMMON_TABLE_EXPR) {
                continue;
            }

            $cteName = strtolower((string)($cteNode[self::NODE_TAG_COMMON_TABLE_EXPR]['ctename'] ?? ''));
            $cteQuery = $cteNode[self::NODE_TAG_COMMON_TABLE_EXPR]['ctequery'] ?? null;

            if ($cteName === '' || !is_array($cteQuery)) {
                continue;
            }

            $cteDefinitions[$cteName] = [
                'query' => $cteQuery,
                'columnNamesSet' => $this->getAliasColumnNamesSet($cteNode[self::NODE_TAG_COMMON_TABLE_EXPR]['aliascolnames'] ?? null),
            ];
        }

        return $cteDefinitions;
    }

    /**
     * @param array<string, mixed> $rangeSubselect
     * @return array<string, bool>|null
     */
    protected function getRangeSubselectOutputColumnsSet(array $rangeSubselect): ?array
    {
        $subquery = $rangeSubselect['subquery'] ?? null;

        if (!is_array($subquery) || $this->getWrappedNodeTag($subquery) !== self::NODE_TAG_SELECT_STMT) {
            return null;
        }

        return $this->getOutputColumnsSet(
            $subquery[self::NODE_TAG_SELECT_STMT],
            $this->getAliasColumnNamesSet($rangeSubselect['alias']['colnames'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $selectStatement
     * @param array<string, bool>|null $overrideColumnNamesSet
     * @return array<string, bool>|null
     */
    protected function getOutputColumnsSet(array $selectStatement, ?array $overrideColumnNamesSet = null): ?array
    {
        if ($overrideColumnNamesSet !== null) {
            return $overrideColumnNamesSet;
        }

        $columnNamesSet = [];

        foreach ($selectStatement['targetList'] ?? [] as $targetNode) {
            if (!is_array($targetNode) || $this->getWrappedNodeTag($targetNode) !== self::NODE_TAG_RES_TARGET) {
                return null;
            }

            $columnName = $this->getOutputColumnName($targetNode[self::NODE_TAG_RES_TARGET]);

            if ($columnName === null) {
                return null;
            }

            $columnNamesSet[$columnName] = true;
        }

        return $columnNamesSet;
    }

    /**
     * @return array<string, bool>|null
     */
    protected function getAliasColumnNamesSet(mixed $aliasColumnNamesNode): ?array
    {
        if ($aliasColumnNamesNode === null) {
            return null;
        }

        if (!is_array($aliasColumnNamesNode) || !array_is_list($aliasColumnNamesNode)) {
            return null;
        }

        $columnNamesSet = [];

        foreach ($aliasColumnNamesNode as $aliasColumnNameNode) {
            if (!is_array($aliasColumnNameNode) || $this->getWrappedNodeTag($aliasColumnNameNode) !== self::NODE_TAG_STRING) {
                return null;
            }

            $columnName = strtolower((string)($aliasColumnNameNode[self::NODE_TAG_STRING]['sval'] ?? ''));

            if ($columnName === '') {
                return null;
            }

            $columnNamesSet[$columnName] = true;
        }

        return $columnNamesSet;
    }

    /**
     * @param array<string, array{type: 'table'|'cte'|'derived', tableName?: string, columnNamesSet?: array<string, bool>|null}> $relationsIndexedByAliases
     * @param array<string, array<string, bool>> $allowedColumnsSetIndexedByTableNames
     */
    protected function resolveUnqualifiedColumnReferenceError(
        string $columnName,
        array $relationsIndexedByAliases,
        array $allowedColumnsSetIndexedByTableNames,
    ): ?string {
        $matchingRelationCount = 0;
        $checkedRelations = [];

        foreach ($relationsIndexedByAliases as $relationName => $relation) {
            if ($relation['type'] === 'table' && isset($relation['tableName'])) {
                if (isset($checkedRelations['table:' . $relation['tableName']])) {
                    continue;
                }

                $checkedRelations['table:' . $relation['tableName']] = true;

                if ($this->isAllowedTableColumnReference($relation['tableName'], $columnName, $allowedColumnsSetIndexedByTableNames)) {
                    $matchingRelationCount++;
                }

                continue;
            }

            if (isset($checkedRelations[$relation['type'] . ':' . $relationName])) {
                continue;
            }

            $checkedRelations[$relation['type'] . ':' . $relationName] = true;

            if ($this->isAllowedDerivedColumnReference($relationName, $columnName, $relationsIndexedByAliases)) {
                $matchingRelationCount++;
            }
        }

        if ($matchingRelationCount === 1) {
            return null;
        }

        if ($matchingRelationCount > 1) {
            return self::ERROR_AMBIGUOUS_UNQUALIFIED_COLUMN;
        }

        return self::ERROR_COLUMN_NOT_EXPOSED;
    }

    /**
     * @param array<string, mixed> $resTarget
     */
    protected function getOutputColumnName(array $resTarget): ?string
    {
        $aliasName = strtolower((string)($resTarget['name'] ?? ''));

        if ($aliasName !== '') {
            return $aliasName;
        }

        $value = $resTarget['val'] ?? null;

        if (!is_array($value)) {
            return null;
        }

        $wrappedNodeTag = $this->getWrappedNodeTag($value);

        return match ($wrappedNodeTag) {
            self::NODE_TAG_COLUMN_REF => $this->getOutputColumnNameFromColumnReference($value[self::NODE_TAG_COLUMN_REF] ?? []),
            self::NODE_TAG_FUNC_CALL => $this->getOutputColumnNameFromFunctionCall($value[self::NODE_TAG_FUNC_CALL] ?? []),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $columnReference
     */
    protected function getOutputColumnNameFromColumnReference(array $columnReference): ?string
    {
        $columnReferenceParts = $this->getColumnReferenceParts($columnReference);

        if ($columnReferenceParts === null || $columnReferenceParts === []) {
            return null;
        }

        return end($columnReferenceParts) ?: null;
    }

    /**
     * @param array<string, mixed> $funcCall
     */
    protected function getOutputColumnNameFromFunctionCall(array $funcCall): ?string
    {
        $functionNameParts = $this->getFunctionNameParts($funcCall);

        if ($functionNameParts === null) {
            return null;
        }

        return end($functionNameParts) ?: null;
    }

    /**
     * @param array<string, mixed> $funcCall
     * @return array<int, string>|null
     */
    protected function getFunctionNameParts(array $funcCall): ?array
    {
        $functionNameParts = [];

        foreach ($funcCall['funcname'] ?? [] as $namePart) {
            if (!is_array($namePart) || $this->getWrappedNodeTag($namePart) !== self::NODE_TAG_STRING) {
                return null;
            }

            $functionNamePart = strtolower((string)($namePart[self::NODE_TAG_STRING]['sval'] ?? ''));

            if ($functionNamePart === '') {
                return null;
            }

            $functionNameParts[] = $functionNamePart;
        }

        return $functionNameParts;
    }

    /**
     * @param array<string, mixed> $funcCall
     */
    protected function getNormalizedFunctionName(array $funcCall): string
    {
        $functionNameParts = $this->getFunctionNameParts($funcCall);

        if ($functionNameParts === null) {
            return '';
        }

        return implode('.', $functionNameParts);
    }

    protected function getAliasName(mixed $aliasNode): ?string
    {
        if (!is_array($aliasNode)) {
            return null;
        }

        $aliasName = strtolower((string)($aliasNode['aliasname'] ?? $aliasNode['Alias']['aliasname'] ?? ''));

        return $aliasName !== '' ? $aliasName : null;
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function getWrappedNodeTag(array $node): ?string
    {
        if (array_is_list($node) || count($node) !== 1) {
            return null;
        }

        $nodeTag = array_key_first($node);

        return is_string($nodeTag) ? $nodeTag : null;
    }

    /**
     * @param array<string, mixed> $selectStatement
     */
    protected function hasAllowedLimit(array $selectStatement): bool
    {
        $limitCount = $selectStatement['limitCount'] ?? null;

        if (!is_array($limitCount) || $this->getWrappedNodeTag($limitCount) !== 'A_Const') {
            return false;
        }

        $integerValue = $limitCount['A_Const']['ival']['ival'] ?? null;

        return is_int($integerValue) && $integerValue >= 0 && $integerValue <= $this->maxReturnedRows;
    }

    protected function getLimitRequiredErrorMessage(): string
    {
        return sprintf(self::ERROR_LIMIT_REQUIRED_FORMAT, $this->maxReturnedRows);
    }
}
