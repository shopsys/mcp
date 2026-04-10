<?php

declare(strict_types=1);

namespace Tests\McpBundle\Unit\Component\Database\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopsys\McpBundle\Component\Database\Query\Exception\SqlQueryParsingException;
use Shopsys\McpBundle\Component\Database\Query\PostgresQueryParser;
use Shopsys\McpBundle\Component\Database\Query\SqlQueryValidator;
use Shopsys\McpBundle\Component\Database\Schema\ExposedSchemaProvider;

class SqlQueryValidatorTest extends TestCase
{
    private const int MAX_RETURNED_ROWS = 500;

    public function testValidateReturnsInvalidResultForEmptyQuery(): void
    {
        $sqlQueryValidator = new SqlQueryValidator(
            $this->createExposedSchemaProvider(),
            $this->createStub(PostgresQueryParser::class),
            self::MAX_RETURNED_ROWS,
        );

        $sqlQueryValidationResult = $sqlQueryValidator->validate('   ');

        $this->assertFalse($sqlQueryValidationResult->isValid);
        $this->assertNull($sqlQueryValidationResult->singleStatementSql);
        $this->assertSame(SqlQueryValidator::ERROR_EMPTY_QUERY, $sqlQueryValidationResult->errorMessage);
    }

    public function testValidateReturnsInvalidResultWhenParserThrowsException(): void
    {
        $postgresQueryParser = $this->createStub(PostgresQueryParser::class);
        $postgresQueryParser->method('parseSingleStatement')
            ->willThrowException(new SqlQueryParsingException('The SQL query could not be parsed.'));

        $sqlQueryValidator = new SqlQueryValidator(
            $this->createExposedSchemaProvider(),
            $postgresQueryParser,
            self::MAX_RETURNED_ROWS,
        );

        $sqlQueryValidationResult = $sqlQueryValidator->validate('SELECT FROM');

        $this->assertFalse($sqlQueryValidationResult->isValid);
        $this->assertNull($sqlQueryValidationResult->singleStatementSql);
        $this->assertSame('The SQL query could not be parsed.', $sqlQueryValidationResult->errorMessage);
    }

    #[DataProvider('provideValidQueries')]
    public function testValidateReturnsValidResultForSupportedReadOnlyQueries(
        string $sql,
    ): void {
        $sqlQueryValidationResult = $this->createSqlQueryValidator()->validate($sql);

        $this->assertTrue($sqlQueryValidationResult->isValid);
        $this->assertNull($sqlQueryValidationResult->errorMessage);
    }

    #[DataProvider('provideInvalidQueries')]
    public function testValidateReturnsInvalidResultForUnsupportedQueries(
        string $sql,
        string $expectedErrorMessage,
    ): void {
        $sqlQueryValidationResult = $this->createSqlQueryValidator()->validate($sql);

        $this->assertFalse($sqlQueryValidationResult->isValid);
        $this->assertNull($sqlQueryValidationResult->singleStatementSql);
        $this->assertSame($expectedErrorMessage, $sqlQueryValidationResult->errorMessage);
    }

    /**
     * @return iterable<string, array{sql: string}>
     */
    public static function provideValidQueries(): iterable
    {
        yield 'simple select against exposed table' => [
            'sql' => 'SELECT id FROM products LIMIT 10',
        ];

        yield 'select without from is allowed' => [
            'sql' => 'SELECT 1 AS value LIMIT 1',
        ];

        yield 'string literal containing comment marker is allowed' => [
            'sql' => 'SELECT \'--\' AS value LIMIT 1',
        ];

        yield 'read only cte against exposed table is allowed' => [
            'sql' => 'WITH product_ids AS (SELECT id FROM products) SELECT id FROM product_ids LIMIT 10',
        ];

        yield 'cte joined under alias is allowed' => [
            'sql' => 'WITH czk_currency AS (SELECT exchange_rate FROM currencies WHERE code = \'CZK\') SELECT ROUND(1 * czk.exchange_rate, 2) AS turnover_czk FROM orders o JOIN czk_currency czk ON TRUE LIMIT 10',
        ];

        yield 'qualified columns in join are allowed' => [
            'sql' => 'SELECT p.id, pt.translatable_id FROM products p JOIN product_translations pt ON pt.translatable_id = p.id LIMIT 10',
        ];

        yield 'translation locale column is allowed' => [
            'sql' => 'SELECT pt.locale FROM product_translations pt LIMIT 10',
        ];

        yield 'unqualified column in join is allowed when it belongs to exactly one relation' => [
            'sql' => 'SELECT catnum FROM products p JOIN product_translations pt ON pt.translatable_id = p.id LIMIT 10',
        ];

        yield 'cte alias column list is allowed' => [
            'sql' => 'WITH c(id2) AS (SELECT id FROM products) SELECT id2 FROM c LIMIT 10',
        ];

        yield 'derived table alias column list is allowed' => [
            'sql' => 'SELECT sub.id2 FROM (SELECT id FROM products) AS sub(id2) LIMIT 10',
        ];

        yield 'order by aggregate alias is allowed' => [
            'sql' => 'SELECT customer_id, COUNT(id) AS order_count FROM orders GROUP BY customer_id ORDER BY order_count DESC, customer_id ASC LIMIT 10',
        ];

        yield 'public schema qualified table and column are allowed' => [
            'sql' => 'SELECT public.products.id FROM public.products LIMIT 10',
        ];
    }

    /**
     * @return iterable<string, array{sql: string, expectedErrorMessage: string}>
     */
    public static function provideInvalidQueries(): iterable
    {
        yield 'update is rejected' => [
            'sql' => 'UPDATE products SET catnum = \'x\'',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_ONLY_SELECT_SUPPORTED,
        ];

        yield 'delete cte is rejected' => [
            'sql' => 'WITH deleted_products AS (DELETE FROM products RETURNING id) SELECT id FROM deleted_products LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_ONLY_SELECT_SUPPORTED,
        ];

        yield 'select into is rejected' => [
            'sql' => 'SELECT id INTO exported_products FROM products LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'missing limit is rejected' => [
            'sql' => 'SELECT id FROM products',
            'expectedErrorMessage' => sprintf(SqlQueryValidator::ERROR_LIMIT_REQUIRED_FORMAT, self::MAX_RETURNED_ROWS),
        ];

        yield 'limit above cap is rejected' => [
            'sql' => sprintf('SELECT id FROM products LIMIT %d', self::MAX_RETURNED_ROWS + 1),
            'expectedErrorMessage' => sprintf(SqlQueryValidator::ERROR_LIMIT_REQUIRED_FORMAT, self::MAX_RETURNED_ROWS),
        ];

        yield 'limit all is rejected' => [
            'sql' => 'SELECT id FROM products LIMIT ALL',
            'expectedErrorMessage' => sprintf(SqlQueryValidator::ERROR_LIMIT_REQUIRED_FORMAT, self::MAX_RETURNED_ROWS),
        ];

        yield 'wildcard select is rejected' => [
            'sql' => 'SELECT * FROM products LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_WILDCARD_SELECT_NOT_SUPPORTED,
        ];

        yield 'nextval is rejected' => [
            'sql' => 'SELECT nextval(\'products_id_seq\') LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'select for update is rejected' => [
            'sql' => 'SELECT id FROM products LIMIT 10 FOR UPDATE',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'setval is rejected' => [
            'sql' => 'SELECT setval(\'products_id_seq\', 1) LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'set config is rejected' => [
            'sql' => 'SELECT set_config(\'search_path\', \'pg_catalog\', false) LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'dblink is rejected' => [
            'sql' => 'SELECT dblink(\'dbname=shopsys\', \'SELECT 1\') LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'pg cancel backend is rejected' => [
            'sql' => 'SELECT pg_cancel_backend(123) LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'pg terminate backend is rejected' => [
            'sql' => 'SELECT pg_terminate_backend(123) LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'pg advisory function is rejected' => [
            'sql' => 'SELECT pg_advisory_lock(1) LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'range function in from clause is rejected' => [
            'sql' => 'SELECT value FROM generate_series(1, 2) AS t(value) LIMIT 1',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNSUPPORTED_READ_WRITE_CONSTRUCT,
        ];

        yield 'non exposed table is rejected' => [
            'sql' => 'SELECT id FROM administrators LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_TABLE_NOT_EXPOSED,
        ];

        yield 'non public schema table is rejected' => [
            'sql' => 'SELECT other.products.id FROM other.products LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_TABLE_NOT_EXPOSED,
        ];

        yield 'hidden single table column is rejected' => [
            'sql' => 'SELECT secret_hash FROM products LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_COLUMN_NOT_EXPOSED,
        ];

        yield 'hidden qualified column is rejected' => [
            'sql' => 'SELECT pt.secret_hash FROM products p JOIN product_translations pt ON pt.translatable_id = p.id LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_COLUMN_NOT_EXPOSED,
        ];

        yield 'hidden unqualified column in join is rejected' => [
            'sql' => 'SELECT secret_hash FROM products p JOIN product_translations pt ON pt.translatable_id = p.id LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_COLUMN_NOT_EXPOSED,
        ];

        yield 'ambiguous unqualified column in join is rejected' => [
            'sql' => 'SELECT id FROM products p JOIN product_translations pt ON pt.translatable_id = p.id LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_AMBIGUOUS_UNQUALIFIED_COLUMN,
        ];

        yield 'unknown qualified relation alias is rejected' => [
            'sql' => 'SELECT missing.id FROM products LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_UNKNOWN_RELATION_ALIAS,
        ];

        yield 'unknown cte column is rejected' => [
            'sql' => 'WITH czk_currency AS (SELECT exchange_rate FROM currencies WHERE code = \'CZK\') SELECT czk.missing_rate FROM czk_currency czk LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_COLUMN_NOT_EXPOSED,
        ];

        yield 'unknown derived table column is rejected' => [
            'sql' => 'SELECT sub.missing_name FROM (SELECT name FROM product_translations) sub LIMIT 10',
            'expectedErrorMessage' => SqlQueryValidator::ERROR_COLUMN_NOT_EXPOSED,
        ];
    }

    private function createSqlQueryValidator(): SqlQueryValidator
    {
        $this->skipTestIfPgQueryExtensionIsMissing();

        return new SqlQueryValidator(
            $this->createExposedSchemaProvider(),
            new PostgresQueryParser(),
            self::MAX_RETURNED_ROWS,
        );
    }

    private function createExposedSchemaProvider(): ExposedSchemaProvider
    {
        $exposedSchemaProvider = $this->createStub(ExposedSchemaProvider::class);
        $exposedSchemaProvider->method('getAllowedColumnsSetIndexedByTableNames')
            ->willReturn($this->getAllowedColumnsSetIndexedByTableNames());

        return $exposedSchemaProvider;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function getAllowedColumnsSetIndexedByTableNames(): array
    {
        return [
            'currencies' => [
                'code' => true,
                'exchange_rate' => true,
            ],
            'orders' => [
                'customer_id' => true,
                'id' => true,
                'currency_code' => true,
            ],
            'products' => [
                'id' => true,
                'catnum' => true,
            ],
            'product_translations' => [
                'id' => true,
                'locale' => true,
                'translatable_id' => true,
                'name' => true,
            ],
        ];
    }

    private function skipTestIfPgQueryExtensionIsMissing(): void
    {
        if (!function_exists('pg_query_split') || !function_exists('pg_query_parse')) {
            $this->markTestSkipped('The pg_query PHP extension must be installed to run validator tests.');
        }
    }
}
