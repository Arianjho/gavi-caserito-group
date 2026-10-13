<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\OrganizationDeletionGuards;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Gestión de sucursales de las empresas de la cuenta.
 */
class BranchController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Branch::class;
    }

    /**
     * Regla de borrado de sucursales.
     */
    protected function guard(): DeletionGuard
    {
        return OrganizationDeletionGuards::forBranch();
    }

    /**
     * Reglas de validación de una sucursal.
     *
     * La empresa solo se define al crear; el código es único por empresa.
     *
     * @param Model|null $current Sucursal en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['nullable', 'digits:6'],
            'is_main' => ['sometimes', 'boolean'],
        ];

        if ($current === null) {
            $companyId = request()->input('company_id');
            $rules['company_id'] = ['required', 'uuid', Rule::exists(Company::class, 'id')->where('status', 'active')];
            $rules['code'] = [
                'required', 'string', 'size:4', 'alpha_num',
                Rule::unique(Branch::class, 'code')->where('company_id', $companyId)->whereNot('status', 'deleted'),
            ];
        }

        return $rules;
    }

    /**
     * Presenta la sucursal incluyendo empresa y código.
     *
     * @param Model $model Sucursal.
     * @return array<string, mixed>
     */
    protected function present(Model $model): array
    {
        return array_merge(parent::present($model), $model->only(['company_id', 'code']));
    }
}
