<?php

namespace App\Actions;

use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\CertificateDocument;
use App\Models\Dispatch;
use App\Models\MotorcycleSerialRequest;
use App\Models\MsCertificado;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\User;
use App\Models\VehicleIdentificationRecordManagement;
use App\MotorcycleSerialRequestStatus;
use App\ReturnStatus;
use App\UserPermission;
use App\VehicleIdentificationRecordManagementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GetDashboardStatistics
{
    /**
     * @return array{
     *     totals: array<string, int>,
     *     master: array<string, int>,
     *     workflows: array<string, array{label: string, route: string, total: int, statuses: array<string, array{label: string, count: int}>}>
     * }
     */
    public function handle(User $user): array
    {
        $totals = [];
        $master = [];
        $workflows = [];

        if ($user->hasPermission(UserPermission::ViewCertificates)) {
            $statuses = $this->statusCounts(new MsCertificado, CertificateStatus::cases());
            $totals['serials'] = array_sum(array_column($statuses, 'count'));

            $nivCounts = DB::connection((new MsCertificado)->getConnectionName())
                ->query()
                ->fromSub(
                    MsCertificado::query()->selectRaw('niv, COUNT(*) as occurrences')->groupBy('niv'),
                    'niv_counts',
                )
                ->selectRaw('COALESCE(SUM(CASE WHEN occurrences > 1 THEN occurrences ELSE 0 END), 0) as duplicates')
                ->selectRaw('COALESCE(SUM(CASE WHEN occurrences = 1 THEN 1 ELSE 0 END), 0) as unique_niv')
                ->first();

            $master['duplicates'] = (int) $nivCounts->duplicates;
            $master['unique_niv'] = (int) $nivCounts->unique_niv;

            foreach (['invalid_niv_length', 'invalid_records'] as $filter) {
                $master[$filter] = MsCertificado::query()->filterByNivStatus($filter)->count();
            }

            $master['certificate_groups'] = MsCertificado::query()->distinct()->count('codigo');

            foreach ($statuses as $value => $status) {
                $master[$value] = $status['count'];
            }
        }

        foreach ([
            'certificates' => [UserPermission::ViewCertificateDocuments, new CertificateDocument],
            'products' => [UserPermission::ViewProducts, new Product],
            'users' => [UserPermission::ViewUsers, new User],
        ] as $key => [$permission, $model]) {
            if ($user->hasPermission($permission)) {
                $totals[$key] = $model->newQuery()->count();
            }
        }

        foreach ([
            'requests' => [UserPermission::ViewMotorcycleSerialRequests, new MotorcycleSerialRequest, MotorcycleSerialRequestStatus::cases(), 'Solicitudes de seriales', 'motorcycle_serial_requests.index'],
            'managements' => [UserPermission::ViewVehicleIdentificationRecordManagement, new VehicleIdentificationRecordManagement, VehicleIdentificationRecordManagementStatus::cases(), 'Gestión de constancias', 'vehicle_identification_record_management.index'],
            'dispatches' => [UserPermission::ViewDispatches, new Dispatch, DispatchStatus::cases(), 'Despachos', 'dispatches.index'],
            'returns' => [UserPermission::ViewReturns, new ProductReturn, ReturnStatus::cases(), 'Devoluciones', 'returns.index'],
        ] as $key => [$permission, $model, $cases, $label, $route]) {
            if (! $user->hasPermission($permission)) {
                continue;
            }

            $statuses = $this->statusCounts($model, $cases);
            $total = array_sum(array_column($statuses, 'count'));
            $totals[$key] = $total;
            $workflows[$key] = compact('label', 'route', 'total', 'statuses');
        }

        return compact('totals', 'master', 'workflows');
    }

    /**
     * @param  list<CertificateStatus|DispatchStatus|ReturnStatus|MotorcycleSerialRequestStatus|VehicleIdentificationRecordManagementStatus>  $cases
     * @return array<string, array{label: string, count: int}>
     */
    private function statusCounts(Model $model, array $cases): array
    {
        $counts = $model->newQuery()->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statuses = [];

        foreach ($cases as $status) {
            $statuses[$status->value] = [
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        return $statuses;
    }
}
