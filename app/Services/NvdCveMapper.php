<?php

namespace App\Services;

class NvdCveMapper
{
    /**
     * @var list<string>
     */
    private const METRIC_FAMILIES = ['cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'];

    /**
     * @param  array<string, mixed>  $cve
     * @return array<string, mixed>
     */
    public function attributes(array $cve): array
    {
        $metric = $this->preferredMetric($cve);

        return [
            'description' => $this->englishDescription($cve),
            'cvss_score' => $this->metricField($metric, 'baseScore'),
            'cvss_vector' => $this->metricField($metric, 'vectorString'),
            'cvss_version' => $this->metricField($metric, 'version'),
            'cvss_severity' => $this->metricField($metric, 'baseSeverity'),
            'published_at' => $cve['published'] ?? null,
            'last_modified_at' => $cve['lastModified'] ?? null,
            'raw_data' => $cve,
        ];
    }

    /**
     * @param  array<string, mixed>  $cve
     * @return array<string, mixed>|null
     */
    private function preferredMetric(array $cve): ?array
    {
        $metrics = $cve['metrics'] ?? [];

        if (! is_array($metrics)) {
            return null;
        }

        foreach (self::METRIC_FAMILIES as $family) {
            $entries = array_filter((array) ($metrics[$family] ?? []), is_array(...));

            if ($entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                if (($entry['type'] ?? null) === 'Primary') {
                    return $entry;
                }
            }

            return reset($entries);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $metric
     */
    private function metricField(?array $metric, string $field): string|float|null
    {
        if ($metric === null) {
            return null;
        }

        return $metric['cvssData'][$field] ?? $metric[$field] ?? null;
    }

    /**
     * @param  array<string, mixed>  $cve
     */
    private function englishDescription(array $cve): ?string
    {
        $descriptions = $cve['descriptions'] ?? [];

        if (! is_array($descriptions)) {
            return null;
        }

        foreach ($descriptions as $description) {
            if (! is_array($description) || ($description['lang'] ?? null) !== 'en') {
                continue;
            }

            return is_string($description['value'] ?? null) ? $description['value'] : null;
        }

        return null;
    }
}
