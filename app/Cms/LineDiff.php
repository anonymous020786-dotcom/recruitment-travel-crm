<?php

declare(strict_types=1);

namespace App\Cms;

/**
 * A line-by-line comparison of two texts (longest common subsequence), for the "compare revisions" screen.
 * Large inputs are compared block-wise so a huge page can never make the comparison slow.
 */
final class LineDiff
{
    private const MAX_LINES = 1500;

    /**
     * @return list<array{op:string,text:string}> op is '=' (same), '-' (only in the old text) or '+' (only in the new one)
     */
    public static function compare(string $old, string $new): array
    {
        $a = self::lines($old);
        $b = self::lines($new);
        if (count($a) > self::MAX_LINES || count($b) > self::MAX_LINES) {
            return array_merge(
                array_map(static fn (string $t): array => ['op' => '-', 'text' => $t], array_slice($a, 0, self::MAX_LINES)),
                array_map(static fn (string $t): array => ['op' => '+', 'text' => $t], array_slice($b, 0, self::MAX_LINES)),
            );
        }
        $n = count($a);
        $m = count($b);
        // trim the common head and tail: most edits touch a small part of the page
        $head = 0;
        while ($head < $n && $head < $m && $a[$head] === $b[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $n - $head && $tail < $m - $head && $a[$n - 1 - $tail] === $b[$m - 1 - $tail]) {
            $tail++;
        }
        $ma = array_slice($a, $head, $n - $head - $tail);
        $mb = array_slice($b, $head, $m - $head - $tail);

        $out = [];
        foreach (array_slice($a, 0, $head) as $t) {
            $out[] = ['op' => '=', 'text' => $t];
        }
        foreach (self::middle($ma, $mb) as $row) {
            $out[] = $row;
        }
        foreach (array_slice($a, $n - $tail) as $t) {
            $out[] = ['op' => '=', 'text' => $t];
        }

        return $out;
    }

    /**
     * @param list<array{op:string,text:string}> $diff
     * @return array{added:int,removed:int}
     */
    public static function stats(array $diff): array
    {
        $s = ['added' => 0, 'removed' => 0];
        foreach ($diff as $d) {
            if ($d['op'] === '+') {
                $s['added']++;
            } elseif ($d['op'] === '-') {
                $s['removed']++;
            }
        }

        return $s;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return $text === '' ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{op:string,text:string}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return array_map(static fn (string $t): array => ['op' => '+', 'text' => $t], $b);
        }
        if ($m === 0) {
            return array_map(static fn (string $t): array => ['op' => '-', 'text' => $t], $a);
        }
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => '=', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['op' => '-', 'text' => $a[$i++]];
            } else {
                $out[] = ['op' => '+', 'text' => $b[$j++]];
            }
        }
        for (; $i < $n; $i++) {
            $out[] = ['op' => '-', 'text' => $a[$i]];
        }
        for (; $j < $m; $j++) {
            $out[] = ['op' => '+', 'text' => $b[$j]];
        }

        return $out;
    }
}
