<?php

namespace App\Actions\Certificates;

use App\Models\CertificateDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ApplyCertificateDateFilter
{
    /** @return array<string, array<mixed>> */
    public static function rules(string $dateFrom = ''): array
    {
        return [
            'dateField' => ['nullable', Rule::in(['created_at', 'request_date', 'issued_on'])],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => array_filter(['nullable', 'date_format:Y-m-d', $dateFrom !== '' ? 'after_or_equal:dateFrom' : null]),
        ];
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function handle(Builder $query, string $dateField, string $dateFrom, string $dateTo): Builder
    {
        if ($dateField === '') {
            return $query;
        }

        if (Validator::make(compact('dateField', 'dateFrom', 'dateTo'), self::rules($dateFrom))->fails()) {
            return $query->whereKey([]);
        }

        $applyRange = function (Builder $query) use ($dateField, $dateFrom, $dateTo): void {
            if ($dateFrom !== '') {
                $query->where($dateField, '>=', $dateFrom);
            }

            if ($dateTo !== '') {
                $query->where($dateField, '<', CarbonImmutable::parse($dateTo)->addDay()->toDateString());
            }
        };

        if ($dateField === 'request_date') {
            $relation = $query->getModel() instanceof CertificateDocument ? 'managements' : 'certificateDocuments.managements';
            $query->whereHas($relation, $applyRange);
        } else {
            $applyRange($query);
        }

        return $query;
    }
}
