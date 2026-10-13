<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\OrganizationDeletionGuards;
use App\Modules\Platform\Application\PlanLimits;
use App\Modules\Platform\Models\Company;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Rules\PeruvianRuc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Gestión de empresas (una por RUC) de la cuenta.
 */
class CompanyController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Company::class;
    }

    /**
     * Regla de borrado de empresas.
     */
    protected function guard(): DeletionGuard
    {
        return OrganizationDeletionGuards::forCompany();
    }

    /**
     * Verifica el tope de empresas del plan antes de crear.
     *
     * @param array<string, mixed> $data Datos validados.
     * @return array<string, mixed>
     */
    protected function extraOnCreate(array $data): array
    {
        app(PlanLimits::class)->ensureCanCreate((string) request()->user()?->tenant_id, 'companies');

        return [];
    }

    /**
     * Reglas de validación de una empresa.
     *
     * @param Model|null $current Empresa en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => [
                'required', new PeruvianRuc,
                Rule::unique(Company::class, 'tax_id')
                    ->whereNot('status', 'deleted')
                    ->ignore($current?->getKey()),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['nullable', 'digits:6'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'igv_applies' => ['sometimes', 'boolean'],
            'fiscal_mode' => ['sometimes', Rule::in(['none', 'optional', 'required'])],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
