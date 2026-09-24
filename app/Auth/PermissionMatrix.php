<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Expands the role → permission tokens of config('permissions.matrix') into concrete permission names.
 *
 *   'module.action'  one permission        '!x'  revoke (applied after every grant)
 *   'module.*'       the whole module      '*'   everything
 */
final class PermissionMatrix
{
    /**
     * @param list<string> $tokens
     * @param list<string> $allNames every permission name in the catalogue
     * @param array<string,list<string>> $byModule module => permission names
     * @return list<string>
     */
    public static function resolve(array $tokens, array $allNames, array $byModule): array
    {
        $granted = [];
        $revoked = [];

        foreach ($tokens as $token) {
            $revoke = str_starts_with($token, '!');
            $token = ltrim($token, '!');

            $names = match (true) {
                $token === '*'              => $allNames,
                str_ends_with($token, '.*') => $byModule[substr($token, 0, -2)] ?? [],
                default                     => [$token],
            };

            foreach ($names as $n) {
                if ($revoke) {
                    $revoked[$n] = true;
                } else {
                    $granted[$n] = true;
                }
            }
        }

        return array_keys(array_diff_key($granted, $revoked));
    }
}
