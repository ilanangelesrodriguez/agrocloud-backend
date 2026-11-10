<?php

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('id_empresa'), app(CompanyContext::class)->company());
        });
        static::creating(function ($model): void {
            $company = app(CompanyContext::class)->company();
            abort_if(isset($model->id_empresa) && (int) $model->id_empresa !== $company, 403, 'Cross-company write rejected.');
            $model->id_empresa = $company;
        });
        static::updating(function ($model): void {
            abort_unless((int) $model->getOriginal('id_empresa') === app(CompanyContext::class)->company()
                && (int) $model->id_empresa === app(CompanyContext::class)->company(), 403, 'Company reassignment rejected.');
        });
    }
}
