<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @phpstan-type ScannedComponent array{vendor: string, product: string, version: string, local_id?: string|null}
 */
class CheckVulnerabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
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
}
