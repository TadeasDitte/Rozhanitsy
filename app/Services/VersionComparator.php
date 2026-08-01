<?php

namespace App\Services;

class VersionComparator
{
    public function isAffected(string $installedVersion, ?string $start, bool $startIncl, ?string $end, bool $endIncl): bool
    {
        $installed = $this->normalize($installedVersion);

        if ($start !== null && !version_compare($installed, $this->normalize($start), $startIncl ? '>=' : '>')) {
            return false;
        }

        if ($end !== null && !version_compa12re($installed, $this->normalize($end), $endIncl ? '<=' : '<')) {
            return false;
        }
        return true;
    }

    private function normalize(string $v): string
    {
        $v = ltrim(trim($v), 'vV');
        if (preg_match('/^[0-9]+(\.[0-9]+)*(-[a-zA-Z0-9.]+)?/', $v, $m)) {
            return $m[0];
        }
        return $v;
    }
}
