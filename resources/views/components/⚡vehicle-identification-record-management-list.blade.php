<?php

use App\Models\VehicleIdentificationRecordManagement;
use App\UserPermission;
use App\VehicleIdentificationRecordManagementStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public $perPage = 10;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewVehicleIdentificationRecordManagement), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(mixed $value): void
    {
        $this->perPage = is_numeric($value) ? max(1, min((int) $value, 10000)) : 10;
        $this->resetPage();
    }

    #[Computed]
    public function records(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return VehicleIdentificationRecordManagement::query()
            ->with([
                'motorcycleSerialRequest.lines:id,motorcycle_serial_request_id,quantity',
                'motorcycleSerialRequest.user:id,name',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->when(is_numeric($search), function (Builder $query) use ($search): void {
                            $query->orWhere('vehicle_identification_record_management.id', (int) $search)
                                ->orWhere('motorcycle_serial_request_id', (int) $search);
                        })
                        ->orWhereHas(
                            'motorcycleSerialRequest.user',
                            fn (Builder $userQuery) => $userQuery->where('name', 'like', "%{$search}%"),
                        );
                });
            })
            ->latest()
            ->paginate((int) $this->perPage);
    }
};
?>

<div>
    <div class="mb-8">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-300">Gestión de constancias</p>
        <h2 class="mt-3 text-3xl font-semibold tracking-tight">Registros generados desde solicitudes finalizadas</h2>
        <p class="mt-3 max-w-3xl leading-7 text-slate-600 dark:text-slate-400">Estos registros se crean automáticamente cuando una solicitud de seriales de motos pasa al estado Hecho.</p>
    </div>

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full max-w-lg">
                <label for="management-search" class="sr-only">Buscar gestiones</label>
                <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/></svg>
                <input id="management-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Buscar por gestión, solicitud o usuario..." class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-12 pr-4 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-emerald-400 focus:ring-4 focus:ring-emerald-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                <span wire:loading wire:target="search" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-medium text-emerald-600 dark:text-emerald-300">Buscando...</span>
                </div>
            </div>
            <x-per-page-selector id="management-records-per-page" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl text-left">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Gestión</th>
                        <th class="px-6 py-4 font-semibold">Solicitud</th>
                        <th class="px-6 py-4 font-semibold">Cantidad</th>
                        <th class="px-6 py-4 font-semibold">Creado por</th>
                        <th class="px-6 py-4 font-semibold">Fecha de creación</th>
                        <th class="px-6 py-4 font-semibold">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->records as $record)
                        <tr wire:key="record-management-{{ $record->id }}" class="transition hover:bg-emerald-50/60 dark:hover:bg-emerald-500/5">
                            <td class="p-0"><a href="{{ route('vehicle_identification_record_management.edit', $record) }}" class="block px-6 py-4 font-semibold text-emerald-700 dark:text-emerald-300">#{{ $record->id }}</a></td>
                            <td class="p-0"><a href="{{ route('vehicle_identification_record_management.edit', $record) }}" class="block px-6 py-4 font-semibold">#{{ $record->motorcycle_serial_request_id }}</a></td>
                            <td class="p-0"><a href="{{ route('vehicle_identification_record_management.edit', $record) }}" class="block px-6 py-4 font-semibold">{{ $record->motorcycleSerialRequest->lines->sum('quantity') }}</a></td>
                            <td class="p-0"><a href="{{ route('vehicle_identification_record_management.edit', $record) }}" class="block px-6 py-4 text-sm">{{ $record->motorcycleSerialRequest->user?->name ?? 'Usuario no disponible' }}</a></td>
                            <td class="p-0"><a href="{{ route('vehicle_identification_record_management.edit', $record) }}" class="block px-6 py-4 text-sm text-slate-500 dark:text-slate-400">{{ $record->created_at?->format('d/m/Y H:i') }}</a></td>
                            <td class="p-0">
                                <a href="{{ route('vehicle_identification_record_management.edit', $record) }}" class="block px-6 py-4">
                                    <span @class([
                                        'inline-flex rounded-full px-3 py-1 text-xs font-semibold',
                                        'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300' => $record->status === VehicleIdentificationRecordManagementStatus::Draft,
                                        'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $record->status === VehicleIdentificationRecordManagementStatus::InProgress,
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $record->status === VehicleIdentificationRecordManagementStatus::Done,
                                    ])>{{ $record->status->label() }}</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-14 text-center"><p class="font-semibold">No se encontraron gestiones</p><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Finaliza una solicitud de seriales para generar el primer registro.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->records->hasPages())
            <div class="border-t border-slate-200 px-6 py-4 dark:border-white/10">{{ $this->records->links() }}</div>
        @endif
    </div>
</div>
