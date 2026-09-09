<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Splits a multi-statement SQL script into individual statements.
 *
 * Aware of: line comments (`-- `, `#`), block comments, single/double-quoted
 * string literals and backtick-quoted identifiers, so a `;` inside any of those
 * does not split a statement.
 *
 * Not aware of: stored routines / triggers with `DELIMITER` blocks. Migration
 * baseline schema intentionally avoids those; if a future migration needs one,
 * give it its own file and run it with Db::unprepared().
 */
final class SqlScript
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $inSingle = $inDouble = $inBacktick = $inLineComment = $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($ch === "\n") {
                    $inLineComment = false;
                    $buffer .= $ch;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($ch === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($ch === '-' && $next === '-') {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
                if ($ch === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
            }

            if ($ch === "'" && !$inDouble && !$inBacktick) {
                // Handle escaped '' inside single-quoted strings.
                if ($inSingle && $next === "'") {
                    $buffer .= "''";
                    $i++;
                    continue;
                }
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '' && !preg_match('/^[\s;]*$/', $trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
