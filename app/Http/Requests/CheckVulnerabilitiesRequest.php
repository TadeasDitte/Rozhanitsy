<?php

namespace App\Http\Requests;

use App\Models\Vulnerability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @phpstan-type ScannedComponent array{vendor: string, product: string, version: string, local_id?: string|null}
 */
class CheckVulnerabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('severity'))) {
            $this->merge([
                'severity' => array_map(
                    fn (mixed $value): mixed => is_string($value) ? Str::upper($value) : $value,
                    $this->input('severity'),
                ),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'string', 'max:64'],

            'components' => ['required', 'array', 'min:1', 'max:2000'],

            'components.*.vendor' => ['required', 'string', 'max:191'],
            'components.*.product' => ['required', 'string', 'max:191'],
            'components.*.version' => ['required', 'string', 'max:64'],

            'components.*.local_id' => ['nullable', 'string', 'max:191'],

            'min_cvss_score' => ['nullable', 'numeric', 'min:0', 'max:10'],

            'severity' => ['nullable', 'array'],
            'severity.*' => ['string', Rule::in(Vulnerability::SEVERITIES)],
        ];
    }

    /**
     * @return list<ScannedComponent>
     */
    public function components(): array
    {
        /** @var list<ScannedComponent> $components */
        $components = $this->validated('components');

        return $components;
    }

    public function tenantId(): ?string
    {
        $tenantId = $this->validated('tenant_id');

        return is_string($tenantId) ? $tenantId : null;
    }

    public function minCvssScore(): ?float
    {
        $minCvssScore = $this->validated('min_cvss_score');

        return is_numeric($minCvssScore) ? (float) $minCvssScore : null;
    }

    /**
     * @return list<string>
     */
    public function severities(): array
    {
        /** @var list<string>|null $severities */
        $severities = $this->validated('severity');

        return $severities ?? [];
    }
}
