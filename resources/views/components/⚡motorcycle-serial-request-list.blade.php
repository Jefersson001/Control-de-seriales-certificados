<?php

use App\Models\MotorcycleSerialRequest;
use App\MotorcycleSerialRequestStatus;
use App\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public $perPage = 10;

    public bool $showDeleteConfirmation = false;

    #[Locked]
    public ?int $deletingRequestId = null;

    #[Locked]
    public string $deletingRequestProduct = '';

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->authorizePermission(UserPermission::ViewMotorcycleSerialRequests);
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

    public function canDeleteRequests(): bool
    {
        return auth()->user()?->hasPermission(UserPermission::DeleteMotorcycleSerialRequests) === true
            || auth()->user()?->hasPermission(UserPermission::DeleteCompletedMotorcycleSerialRequests) === true;
    }

    public function canDeleteRequest(MotorcycleSerialRequest $serialRequest): bool
    {
        $permission = $serialRequest->status === MotorcycleSerialRequestStatus::Done
            ? UserPermission::DeleteCompletedMotorcycleSerialRequests
            : UserPermission::DeleteMotorcycleSerialRequests;

        return auth()->user()?->hasPermission($permission) === true;
    }

    public function openDeleteConfirmation(int $requestId): void
    {
        $serialRequest = MotorcycleSerialRequest::query()->with('lines.product:id,name')->findOrFail($requestId);
        abort_unless($this->canDeleteRequest($serialRequest), 403);

        $this->deletingRequestId = $serialRequest->id;
        $this->deletingRequestProduct = $serialRequest->lines->pluck('product.name')->join(', ');
        $this->showDeleteConfirmation = true;
    }

    public function closeDeleteConfirmation(): void
    {
        $this->reset(['showDeleteConfirmation', 'deletingRequestId', 'deletingRequestProduct']);
    }

    public function deleteRequest(): void
    {
        $serialRequest = MotorcycleSerialRequest::query()->findOrFail($this->deletingRequestId);
        abort_unless($this->canDeleteRequest($serialRequest), 403);

        $serialRequest->delete();

        $this->closeDeleteConfirmation();
        $this->resetPage();
        unset($this->requests);
        $this->statusMessage = 'Solicitud eliminada correctamente.';
    }

    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return MotorcycleSerialRequest::query()
            ->with(['lines.product:id,name', 'user:id,name'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->when(is_numeric($search), fn (Builder $query) => $query->orWhereKey((int) $search))
                        ->orWhereHas('lines.product', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest()
            ->paginate((int) $this->perPage);
    }

    private function authorizePermission(UserPermission $permission): void
    {
        abort_unless(auth()->user()?->hasPermission($permission), 403);
    }
};
?>

<div>
    <div class="mb-8 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-violet-600 dark:text-violet-300">Solicitudes</p>
            <h2 class="mt-3 text-3xl font-semibold tracking-tight">Seriales de motos</h2>
            <p class="mt-3 max-w-2xl leading-7 text-slate-600 dark:text-slate-400">
                Consulta las solicitudes registradas o selecciona una para abrir su formulario.
            </p>
        </div>

        @if (auth()->user()->hasPermission(UserPermission::CreateMotorcycleSerialRequests))
            <a href="{{ route('motorcycle_serial_requests.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-violet-600 px-5 py-3 font-semibold text-white shadow-lg shadow-violet-500/25 transition hover:bg-violet-500 focus:outline-none focus:ring-4 focus:ring-violet-500/30">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Nuevo
            </a>
        @endif
    </div>

    @if ($statusMessage)
        <div role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full max-w-lg">
                <label for="request-search" class="sr-only">Buscar solicitudes</label>
                <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/>
                </svg>
                <input id="request-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Buscar por número, producto o usuario..." class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-12 pr-4 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:ring-4 focus:ring-violet-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                <span wire:loading wire:target="search" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-medium text-violet-600 dark:text-violet-300">Buscando...</span>
                </div>
            </div>
            <x-per-page-selector id="requests-per-page" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-3xl text-left">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Solicitud</th>
                        <th class="px-6 py-4 font-semibold">Productos</th>
                        <th class="px-6 py-4 font-semibold">Creado por</th>
                        <th class="px-6 py-4 font-semibold">Cantidad</th>
                        <th class="px-6 py-4 font-semibold">Estado</th>
                        <th class="px-6 py-4 font-semibold">Fecha</th>
                        @if ($this->canDeleteRequests())
                            <th class="px-6 py-4 text-right font-semibold">Acciones</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->requests as $serialRequest)
                        <tr wire:key="motorcycle-request-{{ $serialRequest->id }}" class="transition hover:bg-violet-50/60 dark:hover:bg-violet-500/5">
                            <td class="p-0">
                                <a href="{{ route('motorcycle_serial_requests.edit', $serialRequest) }}" class="block px-6 py-4 font-semibold text-violet-700 dark:text-violet-300">#{{ $serialRequest->id }}</a>
                            </td>
                            <td class="p-0">
                                <a href="{{ route('motorcycle_serial_requests.edit', $serialRequest) }}" class="block px-6 py-4 font-medium">{{ $serialRequest->lines->pluck('product.name')->join(', ') }}</a>
                            </td>
                            <td class="p-0">
                                <a href="{{ route('motorcycle_serial_requests.edit', $serialRequest) }}" class="block px-6 py-4 text-sm">{{ $serialRequest->user?->name ?? 'Usuario no disponible' }}</a>
                            </td>
                            <td class="p-0">
                                <a href="{{ route('motorcycle_serial_requests.edit', $serialRequest) }}" class="block px-6 py-4 font-semibold">{{ $serialRequest->lines->sum('quantity') }}</a>
                            </td>
                            <td class="p-0">
                                <a href="{{ route('motorcycle_serial_requests.edit', $serialRequest) }}" class="block px-6 py-4">
                                    <span @class([
                                        'inline-flex rounded-full px-3 py-1 text-xs font-semibold',
                                        'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300' => $serialRequest->status === MotorcycleSerialRequestStatus::Draft,
                                        'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $serialRequest->status === MotorcycleSerialRequestStatus::InProgress,
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $serialRequest->status === MotorcycleSerialRequestStatus::Done,
                                    ])>{{ $serialRequest->status->label() }}</span>
                                </a>
                            </td>
                            <td class="p-0">
                                <a href="{{ route('motorcycle_serial_requests.edit', $serialRequest) }}" class="block px-6 py-4 text-sm text-slate-500 dark:text-slate-400">{{ $serialRequest->request_date?->format('d/m/Y') ?? 'Sin fecha' }}</a>
                            </td>
                            @if ($this->canDeleteRequests())
                                <td class="px-6 py-4 text-right">
                                    @if ($this->canDeleteRequest($serialRequest))
                                        <button wire:click="openDeleteConfirmation({{ $serialRequest->id }})" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-100 dark:text-rose-300 dark:hover:bg-rose-500/10">Eliminar</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $this->canDeleteRequests() ? 7 : 6 }}" class="px-6 py-14 text-center">
                                <p class="font-semibold">No se encontraron solicitudes</p>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Crea una solicitud o prueba con otra búsqueda.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->requests->hasPages())
            <div class="border-t border-slate-200 px-6 py-4 dark:border-white/10">{{ $this->requests->links() }}</div>
        @endif
    </div>

    @if ($showDeleteConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-request-title">
            <button wire:click="closeDeleteConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar eliminación"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-6 shadow-2xl dark:border-rose-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.5 2.7 18a2 2 0 0 0 1.74 3h15.12a2 2 0 0 0 1.74-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/></svg>
                    </div>
                    <h3 id="delete-request-title" class="mt-5 text-2xl font-semibold">¿Eliminar esta solicitud?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">Se eliminará permanentemente la solicitud <strong class="text-slate-950 dark:text-white">#{{ $deletingRequestId }}</strong> del producto <strong class="text-slate-950 dark:text-white">{{ $deletingRequestProduct }}</strong>.</p>
                    <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button wire:click="closeDeleteConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">Cancelar</button>
                        <button wire:click="deleteRequest" wire:loading.attr="disabled" wire:target="deleteRequest" type="button" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60 dark:bg-rose-500 dark:hover:bg-rose-400">
                            <span wire:loading.remove wire:target="deleteRequest">Sí, eliminar solicitud</span>
                            <span wire:loading wire:target="deleteRequest">Eliminando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
