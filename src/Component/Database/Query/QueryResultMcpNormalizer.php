<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\Component\Database\Query;

use DateTimeInterface;
use Stringable;

class QueryResultMcpNormalizer
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string|int|float|bool|null>>
     */
    public function normalizeRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string|int|float|bool|null>
     */
    protected function normalizeRow(array $row): array
    {
        return array_map(fn (mixed $value): string|int|float|bool|null => $this->normalizeValue($value), $row);
    }

    protected function normalizeValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Stringable) {
            return (string)$value;
        }

        $encodedValue = json_encode($value);

        return $encodedValue !== false ? $encodedValue : get_debug_type($value);
    }
}
