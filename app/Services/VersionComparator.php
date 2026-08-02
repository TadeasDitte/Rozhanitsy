<?php

namespace App\Services;

class VersionComparator
{
    public function isAffected(string $installedVersion, ?string $start, bool $startIncl, ?string $end, bool $endIncl): bool
    {
        $installed = $this->normalize($installedVersion);

        if ($installed === '') {
            return false;
        }

        if ($start !== null && ! version_compare($installed, $this->normalize($start), $startIncl ? '>=' : '>')) {
            return false;
        }

        if ($end !== null && ! version_compare($installed, $this->normalize($end), $endIncl ? '<=' : '<')) {
            return false;
        }

        return true;
    }

    private function normalize(string $version): string
    {
        $version = ltrim(trim($version), 'vV');

        if (($plus = strpos($version, '+')) !== false) {
            $version = substr($version, 0, $plus);
        }

        if (preg_match('/^[0-9]+(\.[0-9]+)*([-_.][a-zA-Z0-9.]+)?/', $version, $matches)) {
            return $matches[0];
        }

        return $version;
    }
}
