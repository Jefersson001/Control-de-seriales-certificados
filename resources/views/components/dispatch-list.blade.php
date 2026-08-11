<?php

use App\DispatchStatus;
use App\Models\Dispatch;
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
    public int $perPage = 10;

    public bool $showDeleteConfirmation = false;

    #[Locked]
    public ?int $deletingDispatchId = null;

    #[Locked]
    public string $deletingDispatchName = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewDispatches), 403);
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
    public function dispatches(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Dispatch::query()
            ->with('creator:id,name')
            ->withCount('lines')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhereHas('creator', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
            }))
            ->latest()
            ->paginate($this->perPage);
    }

    public function canDeleteDispatches(): bool
    {
        return auth()->user()?->hasPermission(UserPermission::DeleteDispatches) === true;
    }

    public function openDeleteConfirmation(int $dispatchId): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteDispatches), 403);

        $dispatch = Dispatch::query()->findOrFail($dispatchId);

        $this->deletingDispatchId = $dispatch->id;
        $this->deletingDispatchName = $dispatch->name;
        $this->showDeleteConfirmation = true;
    }

    public function closeDeleteConfirmation(): void
    {
        $this->reset(['showDeleteConfirmation', 'deletingDispatchId', 'deletingDispatchName']);
    }

    public function deleteDispatch(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteDispatches), 403);

        Dispatch::query()->findOrFail($this->deletingDispatchId)->delete();

        $this->closeDeleteConfirmation();
        $this->resetPage();
        unset($this->dispatches);
    }
};
?>

<div>
    <div class="mb-8 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Operaciones</p>
            <h2 class="mt-3 text-3xl font-semibold tracking-tight">Despachos</h2>
            <p class="mt-3 max-w-2xl leading-7 text-slate-600 dark:text-slate-400">Consulta los despachos registrados o abre uno para revisar sus NIV.</p>
        </div>
        @if (auth()->user()->hasPermission(UserPermission::CreateDispatches))
            <a href="{{ route('dispatches.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-500">
                <span class="text-xl leading-none">+</span> Nuevo
            </a>
        @endif
    </div>

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full max-w-lg">
                <label for="dispatch-search" class="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-500">Buscar</label>
                <input id="dispatch-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Nombre de Odoo o usuario..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60">
            </div>
            <x-per-page-selector id="dispatches-per-page" />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-4xl text-left">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40">
                    <tr><th class="px-6 py-4">Despacho Odoo</th><th class="px-6 py-4">Fecha</th><th class="px-6 py-4">Creado por</th><th class="px-6 py-4">NIV</th><th class="px-6 py-4">Estado</th>@if ($this->canDeleteDispatches())<th class="px-6 py-4 text-right">Acciones</th>@endif</tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->dispatches as $dispatch)
                        <tr wire:key="dispatch-{{ $dispatch->id }}" class="transition hover:bg-indigo-50/60 dark:hover:bg-indigo-500/5">
                            <td class="p-0"><a href="{{ route('dispatches.edit', $dispatch) }}" class="block px-6 py-4 font-semibold text-indigo-700 dark:text-indigo-300">{{ $dispatch->name }}</a></td>
                            <td class="p-0"><a href="{{ route('dispatches.edit', $dispatch) }}" class="block px-6 py-4">{{ $dispatch->dispatch_date?->format('d/m/Y') ?? 'Sin finalizar' }}</a></td>
                            <td class="p-0"><a href="{{ route('dispatches.edit', $dispatch) }}" class="block px-6 py-4">{{ $dispatch->creator?->name ?? 'Usuario no disponible' }}</a></td>
                            <td class="p-0"><a href="{{ route('dispatches.edit', $dispatch) }}" class="block px-6 py-4 font-semibold">{{ $dispatch->lines_count }}</a></td>
                            <td class="p-0"><a href="{{ route('dispatches.edit', $dispatch) }}" class="block px-6 py-4"><span @class(['rounded-full px-3 py-1 text-xs font-semibold', 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300' => $dispatch->status === DispatchStatus::Draft, 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $dispatch->status === DispatchStatus::InProgress, 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $dispatch->status === DispatchStatus::Done])>{{ $dispatch->status->label() }}</span></a></td>
                            @if ($this->canDeleteDispatches())
                                <td class="px-6 py-4 text-right">
                                    <button wire:click="openDeleteConfirmation({{ $dispatch->id }})" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-100 dark:text-rose-300 dark:hover:bg-rose-500/10">Eliminar</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-14 text-center"><p class="font-semibold">No se encontraron despachos</p><p class="mt-1 text-sm text-slate-500">Crea el primer despacho o cambia la búsqueda.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->dispatches->hasPages())<div class="border-t border-slate-200 px-6 py-4 dark:border-white/10">{{ $this->dispatches->links() }}</div>@endif
    </div>

    @if ($showDeleteConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-dispatch-title">
            <button wire:click="closeDeleteConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar eliminación"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-6 shadow-2xl dark:border-rose-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.5 2.7 18a2 2 0 0 0 1.74 3h15.12a2 2 0 0 0 1.74-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/></svg>
                    </div>
                    <h3 id="delete-dispatch-title" class="mt-5 text-2xl font-semibold">¿Eliminar este despacho?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">
                        Se eliminará permanentemente <strong class="text-slate-950 dark:text-white">{{ $deletingDispatchName }}</strong>. Esta acción no se puede deshacer.
                    </p>
                    <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button wire:click="closeDeleteConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">Cancelar</button>
                        <button wire:click="deleteDispatch" wire:loading.attr="disabled" wire:target="deleteDispatch" type="button" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60 dark:bg-rose-500 dark:hover:bg-rose-400">
                            <span wire:loading.remove wire:target="deleteDispatch">Sí, eliminar despacho</span>
                            <span wire:loading wire:target="deleteDispatch">Eliminando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
