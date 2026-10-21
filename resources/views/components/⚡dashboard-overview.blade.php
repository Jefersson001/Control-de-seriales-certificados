<?php

use App\Actions\GetDashboardStatistics;
use App\CertificateStatus;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        abort_unless(auth()->check(), 403);
    }

    /**
     * @return array{
     *     totals: array<string, int>,
     *     master: array<string, int>,
     *     workflows: array<string, array{label: string, route: string, total: int, statuses: array<string, array{label: string, count: int}>}>
     * }
     */
    #[Computed]
    public function statistics(): array
    {
        return app(GetDashboardStatistics::class)->handle(User::query()->findOrFail(auth()->id()));
    }

};
?>

<div wire:poll.60s.keep-alive class="mx-auto flex max-w-[1600px] flex-col gap-8">
    @php
        $statistics = $this->statistics;
        $cards = [
            'serials' => ['Seriales certificados', 'Registros en el maestro', 'certificates.index', 'M4 4v16m4-16v16m3-16v16m5-16v16m4-16v16'],
            'certificates' => ['Certificados', 'Documentos registrados', 'certificate_documents.index', 'M7 3h7l4 4v14H7V3Zm7 0v5h4M10 12h5m-5 4h5'],
            'requests' => ['Solicitudes', 'Solicitudes de seriales', 'motorcycle_serial_requests.index', 'M9 4H5v17h14V4h-4M9 3h6v4H9V3Zm0 9h6m-6 4h6'],
            'managements' => ['Constancias', 'Gestiones registradas', 'vehicle_identification_record_management.index', 'M9 3H5v18h14V3h-4M9 3v4h6V3H9Zm0 11 2 2 4-5'],
            'dispatches' => ['Despachos', 'Despachos registrados', 'dispatches.index', 'M3 5h11v12H3V5Zm11 5h4l3 4v3h-7M8 18a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm12 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z'],
            'returns' => ['Devoluciones', 'Documentos de devolución', 'returns.index', 'M9 5 3 11l6 6M3 11h12a6 6 0 0 1 6 6v3'],
        ];
        $recordLabels = [
            'duplicates' => 'NIV duplicados',
            'unique_niv' => 'NIV únicos',
            'invalid_niv_length' => 'NIV con longitud inválida',
            'invalid_records' => 'Registros inválidos',
        ];
    @endphp

    @if ($statistics['totals'] === [])
        <div class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-600 dark:border-white/15 dark:text-slate-300">No tienes módulos habilitados para consultar estadísticas.</div>
    @else
        <section aria-label="Totales por módulo" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cards as $key => [$label, $description, $route, $iconPath])
                @if (isset($statistics['totals'][$key]))
                    <div wire:key="total-{{ $key }}" class="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md dark:border-white/10 dark:bg-slate-900 dark:hover:border-indigo-500/50">
                        <a href="{{ route($route) }}" class="block">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ $label }}</h3>
                            <span class="grid size-10 place-items-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}"/></svg></span>
                        </div>
                        <p class="mt-4 text-4xl font-semibold tracking-tight text-slate-950 tabular-nums dark:text-white">{{ number_format($statistics['totals'][$key], 0, ',', '.') }}</p>
                        <div class="mt-3 flex items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400"><span>{{ $description }}</span><span class="text-indigo-600 group-hover:underline dark:text-indigo-300">Ver módulo &rarr;</span></div>
                        </a>
                        @if ($key === 'returns' && isset($statistics['master']['returned']))
                            <a href="{{ route('certificates.index', ['status' => CertificateStatus::Returned->value]) }}" class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 text-sm text-rose-700 hover:underline dark:border-white/10 dark:text-rose-300">
                                <!-- <span>Seriales devueltos en el maestro</span>
                                <strong class="tabular-nums">{{ number_format($statistics['master']['returned'], 0, ',', '.') }} &rarr;</strong> -->
                            </a>
                        @endif
                    </div>
                @endif
            @endforeach
        </section>
    @endif

    @if (isset($statistics['totals']['serials']))
        <section aria-labelledby="master-heading" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 p-6 dark:border-white/10">
                <div><p class="text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-300">Maestro de seriales</p><h2 id="master-heading" class="mt-2 text-xl font-semibold">Maestro de seriales certificados</h2></div>
                <a href="{{ route('certificates.index') }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Consultar maestro &rarr;</a>
            </div>
            <div class="grid gap-6 p-6 xl:grid-cols-2">
                <div class="flex flex-col gap-5">
                    <h3 class="text-sm font-semibold">Clasificación de registros</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($recordLabels as $key => $label)
                            <a wire:key="record-{{ $key }}" href="{{ route('certificates.index', ['records' => $key]) }}" class="rounded-2xl bg-slate-50 p-4 transition hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:bg-slate-950/50 dark:hover:bg-indigo-500/10">
                                <p class="text-xs leading-5 text-slate-600 dark:text-slate-400">{{ $label }}</p>
                                <p class="mt-2 text-2xl font-semibold tabular-nums">{{ number_format($statistics['master'][$key], 0, ',', '.') }}</p>
                            </a>
                        @endforeach
                    </div>
                    <!-- <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">Se cuentan registros del maestro. Un NIV duplicado puede aparecer en varios registros y también ser inválido; estas categorías pueden coincidir.</p> -->
                    <a href="{{ route('certificates.index', ['records' => 'group_by_certificate']) }}" class="text-sm text-slate-600 hover:underline dark:text-slate-300"><strong class="text-slate-950 dark:text-white">{{ number_format($statistics['master']['certificate_groups'], 0, ',', '.') }}</strong> números de certificado diferentes en el maestro.</a>
                </div>
                <div class="flex flex-col gap-5">
                    <h3 class="text-sm font-semibold">Distribución por status</h3>
                    @foreach (CertificateStatus::cases() as $status)
                        @php
                            $count = $statistics['master'][$status->value];
                            $percentage = $statistics['totals']['serials'] > 0 ? round($count / $statistics['totals']['serials'] * 100, 1) : 0;
                        @endphp
                        <a wire:key="serial-status-{{ $status->value }}" href="{{ route('certificates.index', ['status' => $status->value]) }}" class="rounded-lg transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-white/5">
                            <div class="mb-2 flex items-center justify-between gap-3 text-sm"><span class="text-slate-600 dark:text-slate-300">{{ $status->label() }}</span><span class="font-semibold tabular-nums">{{ number_format($count, 0, ',', '.') }} <span class="ml-2 text-xs font-normal text-slate-500 dark:text-slate-400">{{ $percentage }}%</span></span></div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/5" aria-hidden="true"><div @class(['h-full rounded-full', 'bg-amber-400' => $status === CertificateStatus::PendingDispatch, 'bg-emerald-500' => $status === CertificateStatus::Dispatched, 'bg-rose-400' => $status === CertificateStatus::Returned]) style="width: {{ $percentage }}%"></div></div>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($statistics['workflows'] !== [])
        <section aria-labelledby="operations-heading" class="flex flex-col gap-4">
            <div><p class="text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-300">Seguimiento operativo</p><h2 id="operations-heading" class="mt-2 text-xl font-semibold">Solicitudes, constancias y movimientos</h2></div>
            <div class="grid gap-5 xl:grid-cols-2">
                @foreach ($statistics['workflows'] as $key => $workflow)
                    <article wire:key="workflow-{{ $key }}" class="flex flex-col gap-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-slate-900">
                        <div class="flex items-start justify-between gap-4"><div><h3 class="font-semibold">{{ $workflow['label'] }}</h3><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ number_format($workflow['total'], 0, ',', '.') }} en total</p></div><a href="{{ route($workflow['route']) }}" class="shrink-0 text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Ver listado &rarr;</a></div>
                        <div class="grid grid-cols-3 gap-3">
                            @foreach ($workflow['statuses'] as $value => $status)
                                <a wire:key="workflow-{{ $key }}-{{ $value }}" href="{{ route($workflow['route'], ['status' => $value]) }}" @class(['rounded-xl border border-transparent p-3 transition hover:shadow-sm focus-visible:outline-2 focus-visible:outline-indigo-500', 'bg-slate-50 dark:bg-white/5' => $value === 'draft', 'bg-amber-50 dark:bg-amber-500/10' => $value === 'in_progress', 'bg-emerald-50 dark:bg-emerald-500/10' => $value === 'done'])>
                                    <p class="text-2xl font-semibold tabular-nums">{{ number_format($status['count'], 0, ',', '.') }}</p><p class="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-300">{{ $status['label'] }}</p>
                                </a>
                            @endforeach
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Todos los estados</p>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if (isset($statistics['totals']['products']) || isset($statistics['totals']['users']))
        <section aria-labelledby="configuration-heading" class="flex flex-col gap-4">
            <div><p class="text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-300">Configuración</p><h2 id="configuration-heading" class="mt-2 text-xl font-semibold">Productos y usuarios</h2></div>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['products' => ['Productos', 'products.index', 'Productos registrados en el catálogo'], 'users' => ['Usuarios', 'users.index', 'Cuentas registradas en el sistema']] as $key => [$label, $route, $description])
                    @if (isset($statistics['totals'][$key]))
                        <a wire:key="configuration-{{ $key }}" href="{{ route($route) }}" class="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-indigo-300 dark:border-white/10 dark:bg-slate-900 dark:hover:border-indigo-500/50"><div><h3 class="font-semibold">{{ $label }}</h3><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p><span class="mt-3 inline-block text-xs font-semibold text-indigo-600 dark:text-indigo-300">Ver módulo &rarr;</span></div><span class="text-4xl font-semibold tabular-nums">{{ number_format($statistics['totals'][$key], 0, ',', '.') }}</span></a>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    <p wire:loading role="status" class="text-sm text-indigo-600 dark:text-indigo-300">Actualizando cantidades…</p>
</div>
