<?php

use App\Actions\Dispatches\FinalizeDispatch;
use App\Actions\Dispatches\ImportDispatchNivs;
use App\CertificateStatus;
use App\DispatchStatus;
use App\Models\Dispatch;
use App\Models\MsCertificado;
use App\UserPermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $dispatchId = null;

    public string $name = '';
    public string $status = 'draft';
    public string $nivSearch = '';
    public array $selectedIds = [];
    public mixed $importFile = null;
    public bool $showFinalizeConfirmation = false;
    public ?string $statusMessage = null;
    public ?string $importMessage = null;

    public function mount(?int $dispatchId = null): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewDispatches), 403);
        $this->dispatchId = $dispatchId;

        if ($dispatchId !== null) {
            $dispatch = Dispatch::query()->with('lines:id,dispatch_id,ms_certificado_id')->findOrFail($dispatchId);
            $this->name = $dispatch->name;
            $this->status = $dispatch->status->value;
            $this->selectedIds = $dispatch->lines->pluck('ms_certificado_id')->map(fn ($id) => (int) $id)->all();
        }
    }

    #[Computed]
    public function dispatchRecord(): ?Dispatch
    {
        return $this->dispatchId === null
            ? null
            : Dispatch::query()->with('creator:id,name')->findOrFail($this->dispatchId);
    }

    public function isDone(): bool
    {
        return $this->status === DispatchStatus::Done->value;
    }

    public function setStatus(string $status): void
    {
        $this->authorizeEdit();
        $newStatus = DispatchStatus::tryFrom($status);
        abort_if($newStatus === null || $newStatus === DispatchStatus::Done, 422);

        $this->status = $newStatus->value;
    }

    #[Computed]
    public function selectedRecords(): Collection
    {
        if ($this->selectedIds === []) {
            return new Collection;
        }

        $positions = array_flip($this->selectedIds);

        return MsCertificado::query()
            ->whereKey($this->selectedIds)
            ->get(['id', 'niv', 'marca', 'modelo', 'codigo', 'status'])
            ->sortBy(fn (MsCertificado $record) => $positions[$record->id] ?? PHP_INT_MAX)
            ->values();
    }

    #[Computed]
    public function candidates(): Collection
    {
        $search = trim($this->nivSearch);

        if (mb_strlen($search) < 2 || $this->isDone()) {
            return new Collection;
        }

        return MsCertificado::query()
            ->where('status', CertificateStatus::PendingDispatch)
            ->whereNotIn('id', $this->selectedIds)
            ->where('niv', 'like', "%{$search}%")
            ->orderBy('niv')
            ->limit(20)
            ->get(['id', 'niv', 'marca', 'modelo', 'codigo']);
    }

    public function addCertificate(int $id): void
    {
        $this->authorizeEdit();
        $record = MsCertificado::query()
            ->whereKey($id)
            ->where('status', CertificateStatus::PendingDispatch)
            ->firstOrFail();

        if ($this->selectedRecords->contains('niv', $record->niv)) {
            $this->addError('nivSearch', 'Ese NIV ya está incluido en el despacho.');
            return;
        }

        $this->selectedIds[] = $record->id;
        $this->selectedIds = array_values(array_unique($this->selectedIds));
        $this->nivSearch = '';
        unset($this->selectedRecords, $this->candidates);
    }

    public function removeCertificate(int $id): void
    {
        $this->authorizeEdit();
        $this->selectedIds = array_values(array_filter($this->selectedIds, fn (int $selectedId) => $selectedId !== $id));
        unset($this->selectedRecords);
    }

    public function importWorkbook(ImportDispatchNivs $importer): void
    {
        $this->authorizeEdit();
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx', 'max:'.max(1, intdiv((int) config('livewire.payload.max_size', 1048576), 1024))],
        ], [
            'importFile.required' => 'Selecciona un archivo Excel.',
            'importFile.mimes' => 'El archivo debe tener formato XLSX.',
        ]);

        try {
            $result = $importer->handle($this->importFile->getRealPath());
            $existingNivs = $this->selectedRecords->pluck('niv')->flip();
            $added = 0;

            foreach ($result['records'] as $record) {
                if ($existingNivs->has($record['niv'])) {
                    continue;
                }

                $this->selectedIds[] = (int) $record['id'];
                $existingNivs->put($record['niv'], true);
                $added++;
            }

            $this->selectedIds = array_values(array_unique($this->selectedIds));
            $this->importMessage = "Se agregaron {$added} NIV. {$result['unavailable']} valores no coincidieron con registros disponibles por despachar.";
            $this->importFile = null;
            unset($this->selectedRecords);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('importFile', 'No fue posible leer el Excel. Verifica que el archivo no esté dañado.');
        }
    }

    public function save(): mixed
    {
        try {
            $this->persist();
            session()->flash('dispatchSaved', 'Despacho guardado correctamente.');
        } catch (RuntimeException $exception) {
            $this->addError('selectedIds', $exception->getMessage());

            return null;
        }

        return redirect()->route('dispatches.edit', $this->dispatchId);
    }

    public function openFinalizeConfirmation(): void
    {
        $this->authorizeEdit();

        if ($this->selectedIds === []) {
            $this->addError('selectedIds', 'Agrega al menos un NIV antes de finalizar.');
            return;
        }

        $this->showFinalizeConfirmation = true;
    }

    public function closeFinalizeConfirmation(): void
    {
        $this->showFinalizeConfirmation = false;
    }

    public function finalize(FinalizeDispatch $finalizer): mixed
    {
        $this->authorizeEdit();

        try {
            $dispatch = $this->persist();
            $finalizer->handle($dispatch);
            $this->showFinalizeConfirmation = false;
            unset($this->dispatchRecord, $this->selectedRecords);
            session()->flash('dispatchSaved', 'Despacho finalizado. Los NIV seleccionados ahora están en estado Despachado.');

            return redirect()->route('dispatches.edit', $dispatch);
        } catch (RuntimeException $exception) {
            $this->showFinalizeConfirmation = false;
            $this->addError('selectedIds', $exception->getMessage());
            return null;
        }
    }

    private function persist(): Dispatch
    {
        $this->authorizeEdit();
        abort_if($this->isDone(), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('dispatches', 'name')->ignore($this->dispatchId)],
            'selectedIds' => ['required', 'array', 'min:1'],
            'selectedIds.*' => ['integer', 'distinct', 'exists:ms_certificados,id'],
        ], [
            'name.required' => 'Ingresa el nombre del despacho generado en Odoo.',
            'name.unique' => 'Ya existe un despacho con ese nombre de Odoo.',
            'selectedIds.required' => 'Agrega al menos un NIV.',
            'selectedIds.min' => 'Agrega al menos un NIV.',
        ]);

        $availableCount = MsCertificado::query()
            ->whereKey($validated['selectedIds'])
            ->where('status', CertificateStatus::PendingDispatch)
            ->count();

        if ($availableCount !== count($validated['selectedIds'])) {
            throw new RuntimeException('Uno o más NIV ya no están disponibles en estado Por despachar.');
        }

        return DB::transaction(function () use ($validated): Dispatch {
            $dispatch = $this->dispatchId === null
                ? Dispatch::query()->create([
                    'name' => trim($validated['name']),
                    'created_by' => auth()->id(),
                    'status' => $this->status,
                ])
                : Dispatch::query()->lockForUpdate()->findOrFail($this->dispatchId);

            if ($this->dispatchId !== null) {
                $dispatch->update([
                    'name' => trim($validated['name']),
                    'status' => $this->status,
                ]);
            }

            $dispatch->lines()->delete();
            $dispatch->lines()->createMany(array_map(
                fn (int $id): array => ['ms_certificado_id' => $id],
                $validated['selectedIds'],
            ));
            $this->dispatchId = $dispatch->id;
            unset($this->dispatchRecord);

            return $dispatch;
        });
    }

    private function authorizeEdit(): void
    {
        $permission = $this->dispatchId === null ? UserPermission::CreateDispatches : UserPermission::EditDispatches;
        abort_unless(auth()->user()?->hasPermission($permission), 403);
        abort_if($this->dispatchId !== null && $this->isDone(), 403);
    }
};
?>

<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <a href="{{ route('dispatches.index') }}" class="font-semibold text-indigo-600 hover:text-indigo-500">← Volver a la lista</a>
        <div class="flex flex-wrap justify-end gap-3">
            @if (! $this->isDone() && auth()->user()->hasPermission($dispatchId === null ? UserPermission::CreateDispatches : UserPermission::EditDispatches))
                <button wire:click="save" wire:loading.attr="disabled" wire:target="save" type="button" class="rounded-xl border border-indigo-200 bg-white px-5 py-3 font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-500/30 dark:bg-transparent dark:text-indigo-300">Guardar</button>
                <button wire:click="openFinalizeConfirmation" type="button" class="rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white shadow-lg shadow-emerald-500/20 hover:bg-emerald-500">Finalizar</button>
            @endif
        </div>
    </div>

    @if (session('dispatchSaved'))<div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('dispatchSaved') }}</div>@endif
    @if ($errors->any())<div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200"><ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="border-b border-slate-200 bg-slate-50/70 p-6 dark:border-white/10 dark:bg-slate-950/30 sm:p-8">
            <p class="mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Estado del despacho</p>
            <div class="grid grid-cols-3 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                @foreach ([DispatchStatus::Draft, DispatchStatus::InProgress, DispatchStatus::Done] as $availableStatus)
                    <button
                        wire:key="dispatch-status-{{ $availableStatus->value }}"
                        @if ($availableStatus !== DispatchStatus::Done)
                            wire:click="setStatus('{{ $availableStatus->value }}')"
                        @else
                            title="El estado Hecho solo se asigna mediante la acción de finalizar"
                        @endif
                        type="button"
                        @disabled($this->isDone() || $availableStatus === DispatchStatus::Done)
                        @class([
                            'relative px-3 py-3 text-sm font-semibold transition sm:px-5',
                            'border-l border-slate-200 dark:border-white/10' => ! $loop->first,
                            'bg-slate-700 text-white dark:bg-slate-600' => $status === $availableStatus->value && $availableStatus === DispatchStatus::Draft,
                            'bg-amber-500 text-white' => $status === $availableStatus->value && $availableStatus === DispatchStatus::InProgress,
                            'bg-emerald-600 text-white' => $status === $availableStatus->value && $availableStatus === DispatchStatus::Done,
                            'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/5' => $status !== $availableStatus->value && ! $this->isDone() && $availableStatus !== DispatchStatus::Done,
                            'cursor-not-allowed text-slate-400' => $status !== $availableStatus->value && ($this->isDone() || $availableStatus === DispatchStatus::Done),
                        ])
                    >
                        {{ $availableStatus->label() }}
                    </button>
                @endforeach
            </div>
            @if ($this->dispatchRecord?->finalized_at)<p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Finalizado el {{ $this->dispatchRecord->finalized_at->format('d/m/Y H:i') }}</p>@endif
        </div>

        <div class="border-b border-slate-200 p-6 dark:border-white/10 sm:p-8">
            <div>
                <label class="mb-2 block text-sm font-semibold">Nombre del despacho en Odoo</label>
                <input wire:model="name" type="text" @disabled($this->isDone()) placeholder="Ej. WH/OUT/00045" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 disabled:opacity-70 dark:border-white/10 dark:bg-slate-950/60">
            </div>
        </div>

        <div class="p-6 sm:p-8">
            <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-xl font-semibold">NIV del despacho</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Registros relacionados con el Maestro de Seriales Certificados. {{ count($selectedIds) }} seleccionados.</p>
                </div>
                @if (! $this->isDone())
                    <label class="inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-xl bg-violet-600 px-5 py-3 font-semibold text-white shadow-sm transition hover:bg-violet-500">
                        <input wire:model="importFile" type="file" accept=".xlsx" class="sr-only">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0-4 4m4-4 4 4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/></svg>
                        Seleccionar Excel
                    </label>
                @endif
            </div>

            @if (! $this->isDone())
                <div wire:loading wire:target="importFile" class="mb-6 w-full rounded-2xl border border-violet-200 bg-violet-50 px-5 py-4 text-sm font-semibold text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-200">
                    <span class="inline-flex items-center gap-2">
                        <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"/></svg>
                        Cargando archivo...
                    </span>
                </div>

                @if ($importFile)
                    <div wire:loading.remove wire:target="importFile" class="mb-6 flex w-full flex-col gap-4 rounded-2xl border border-violet-200 bg-violet-50 p-5 dark:border-violet-500/20 dark:bg-violet-500/10 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-violet-950 dark:text-violet-100">{{ $importFile->getClientOriginalName() }}</p>
                            <p class="mt-2 text-sm text-violet-700 dark:text-violet-300">Se buscarán NIV de 17 caracteres en todas las hojas del archivo.</p>
                        </div>
                        <button wire:click="importWorkbook" wire:loading.attr="disabled" wire:target="importWorkbook" type="button" class="shrink-0 rounded-xl bg-violet-600 px-5 py-3 font-semibold text-white transition hover:bg-violet-500 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="importWorkbook">Procesar Excel</span>
                            <span wire:loading wire:target="importWorkbook">Procesando...</span>
                        </button>
                    </div>
                @endif

                @error('importFile') <p class="mb-6 rounded-xl bg-rose-100 px-4 py-3 text-sm font-medium text-rose-700 dark:bg-rose-500/15 dark:text-rose-200">{{ $message }}</p> @enderror
                @if ($importMessage)<p role="status" class="mb-6 rounded-xl bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">{{ $importMessage }}</p>@endif

                <div>
                    <div class="relative rounded-2xl border border-slate-200 p-5 dark:border-white/10">
                        <label class="mb-2 block font-semibold">Seleccionar manualmente</label>
                        <input wire:model.live.debounce.300ms="nivSearch" type="search" placeholder="Busca un NIV por al menos 2 caracteres..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-indigo-400 dark:border-white/10 dark:bg-slate-950/60">
                        @if ($this->candidates->isNotEmpty())
                            <div class="absolute left-5 right-5 z-20 mt-2 max-h-72 overflow-auto rounded-xl border border-slate-200 bg-white shadow-xl dark:border-white/10 dark:bg-slate-900">
                                @foreach ($this->candidates as $candidate)
                                    <button wire:click="addCertificate({{ $candidate->id }})" type="button" class="block w-full border-b border-slate-100 px-4 py-3 text-left transition last:border-0 hover:bg-indigo-50 dark:border-white/5 dark:hover:bg-indigo-500/10"><span class="font-mono font-semibold">{{ $candidate->niv }}</span><span class="mt-1 block text-xs text-slate-500">{{ $candidate->marca }} · {{ $candidate->modelo }} · {{ $candidate->codigo }}</span></button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="mt-6 max-h-[32rem] overflow-auto rounded-2xl border border-slate-200 dark:border-white/10">
                <table class="w-full min-w-4xl text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-100 text-xs uppercase text-slate-600 dark:bg-slate-900 dark:text-slate-300"><tr><th class="px-4 py-3">NIV</th><th class="px-4 py-3">Marca</th><th class="px-4 py-3">Modelo</th><th class="px-4 py-3">Certificado</th>@if (! $this->isDone())<th class="px-4 py-3 text-right">Acción</th>@endif</tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @forelse ($this->selectedRecords as $record)
                            <tr wire:key="dispatch-record-{{ $record->id }}"><td class="px-4 py-3 font-mono font-semibold">{{ $record->niv }}</td><td class="px-4 py-3">{{ $record->marca }}</td><td class="px-4 py-3">{{ $record->modelo }}</td><td class="px-4 py-3 font-mono">{{ $record->codigo }}</td>@if (! $this->isDone())<td class="px-4 py-3 text-right"><button wire:click="removeCertificate({{ $record->id }})" type="button" class="font-semibold text-rose-600 hover:text-rose-500">Quitar</button></td>@endif</tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">Todavía no has seleccionado NIV para este despacho.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($showFinalizeConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
            <button wire:click="closeFinalizeConfirmation" type="button" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm"></button>
            <div class="relative flex min-h-full items-center justify-center p-4"><div class="w-full max-w-lg rounded-3xl bg-white p-7 text-center shadow-2xl dark:bg-slate-900"><div class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">✓</div><h3 class="mt-5 text-2xl font-semibold">¿Finalizar este despacho?</h3><p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">Los {{ count($selectedIds) }} registros cambiarán de Por despachar a Despachado y el formulario quedará bloqueado.</p><div class="mt-7 flex justify-center gap-3"><button wire:click="closeFinalizeConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold dark:border-white/15">Cancelar</button><button wire:click="finalize" wire:loading.attr="disabled" wire:target="finalize" type="button" class="rounded-xl bg-emerald-600 px-7 py-3 font-semibold text-white hover:bg-emerald-500 disabled:opacity-60"><span wire:loading.remove wire:target="finalize">Sí, finalizar</span><span wire:loading wire:target="finalize">Finalizando...</span></button></div></div></div>
        </div>
    @endif
</div>
