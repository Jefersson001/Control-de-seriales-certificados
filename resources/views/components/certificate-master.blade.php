<?php

use App\Models\MsCertificado;
use App\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public $importFile;

    /** @var array<int, array{row: int, reason: string, values: array<int, string>}> */
    #[Locked]
    public array $importSkippedRows = [];

    #[Locked]
    public int $importSkippedCount = 0;

    public string $search = '';

    public $perPage = 10;

    public string $recordFilter = 'all';

    public bool $showDeleteConfirmation = false;

    #[Locked]
    public string $deleteSearch = '';

    #[Locked]
    public string $deleteRecordFilter = 'all';

    #[Locked]
    public int $deleteCount = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewCertificates), 403);
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

    public function updatedRecordFilter(): void
    {
        $this->recordFilter = in_array($this->recordFilter, ['all', 'duplicates', 'invalid_niv_length', 'invalid_records'], true)
            ? $this->recordFilter
            : 'all';
        $this->resetPage();
    }

    public function import(App\Actions\Certificates\ImportCertificates $importCertificates): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ImportCertificates), 403);
        $this->importSkippedRows = [];
        $this->importSkippedCount = 0;

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ], [
            'importFile.required' => 'Selecciona el archivo de Excel que deseas importar.',
            'importFile.mimes' => 'El archivo debe tener formato XLSX.',
            'importFile.max' => 'El archivo no puede superar los 10 MB.',
        ]);

        try {
            $result = $importCertificates->handle($this->importFile->getRealPath());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('importFile', $exception->getMessage());

            return;
        }

        $this->reset('importFile');
        $this->importSkippedRows = $result['skippedRows'];
        $this->importSkippedCount = $result['skipped'];
        $this->resetPage();
        unset($this->certificates);

        session()->flash(
            'importResult',
            "Importación completada: {$result['imported']} registros cargados y {$result['skipped']} filas omitidas.",
        );
    }

    public function openDeleteConfirmation(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteCertificates), 403);

        $this->deleteSearch = trim($this->search);
        $this->deleteRecordFilter = $this->recordFilter;
        $this->deleteCount = MsCertificado::query()
            ->search($this->deleteSearch)
            ->filterByNivStatus($this->deleteRecordFilter)
            ->count();
        $this->showDeleteConfirmation = true;
    }

    public function closeDeleteConfirmation(): void
    {
        $this->reset(['showDeleteConfirmation', 'deleteSearch', 'deleteRecordFilter', 'deleteCount']);
    }

    public function deleteRecords(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteCertificates), 403);

        $deleted = MsCertificado::query()
            ->search($this->deleteSearch)
            ->filterByNivStatus($this->deleteRecordFilter)
            ->delete();

        $this->closeDeleteConfirmation();
        $this->resetPage();
        unset($this->certificates);

        session()->flash('deletionResult', "Se eliminaron {$deleted} registros correctamente.");
    }

    #[Computed]
    public function certificates(): LengthAwarePaginator
    {
        return MsCertificado::query()
            ->select([
                'id',
                'no',
                'marca',
                'modelo',
                'tipo',
                'fabricacion',
                'anio',
                'niv',
                'codigo',
            ])
            ->search($this->search)
            ->filterByNivStatus($this->recordFilter)
            ->latest('id')
            ->paginate((int) $this->perPage);
    }
};
?>

<div>
    <div class="mb-8">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Consulta</p>
        <h2 class="mt-3 text-3xl font-semibold tracking-tight">Maestro Seriales Certificados</h2>
        <p class="mt-3 max-w-3xl leading-7 text-slate-600 dark:text-slate-400">
            Consulta los seriales registrados. Esta información es únicamente de lectura.
        </p>
    </div>

    <div class="mb-5 flex flex-wrap gap-3">
        @if (auth()->user()->hasPermission(UserPermission::ImportCertificates))
            <label class="inline-flex cursor-pointer items-center justify-center rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400">
                <input wire:model="importFile" type="file" accept=".xlsx" class="sr-only">
                Importar Excel
            </label>
        @endif
        <a href="{{ route('certificates.export', ['search' => trim($search)]) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-slate-950 bg-white px-4 py-3 text-sm font-bold text-slate-950 shadow-sm transition hover:bg-slate-950 hover:text-white dark:border-white dark:bg-slate-900 dark:text-white dark:hover:bg-white dark:hover:text-slate-950">
            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/>
            </svg>
            <span>Exportar data</span>
        </a>
        @if (auth()->user()->hasPermission(UserPermission::DeleteCertificates))
            <button wire:click="openDeleteConfirmation" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700 dark:bg-rose-500 dark:hover:bg-rose-400">
                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 7 .75 13h10.5L18 7M9 7V4h6v3M4 7h16"/>
                </svg>
                Eliminar registros
            </button>
        @endif
    </div>

    @if ($importFile)
        <div class="mb-5 flex flex-col gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-indigo-400/20 dark:bg-indigo-500/10">
            <div>
                <p class="font-semibold text-indigo-950 dark:text-indigo-100">{{ $importFile->getClientOriginalName() }}</p>
                <p class="mt-1 text-sm text-indigo-700 dark:text-indigo-300">Los registros se cargarán tal como aparecen, incluso si existen NIV duplicados.</p>
            </div>
            <button wire:click="import" wire:loading.attr="disabled" wire:target="import,importFile" type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="import">Confirmar importación</span>
                <span wire:loading wire:target="import">Importando...</span>
            </button>
        </div>
    @endif

    @if (session('importResult'))
        <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-500/10 dark:text-emerald-200">
            {{ session('importResult') }}
        </div>
    @endif

    @if ($importSkippedRows !== [])
        <section class="mb-5 overflow-hidden rounded-2xl border border-amber-200 bg-amber-50 dark:border-amber-400/20 dark:bg-amber-500/10">
            <div class="px-5 py-4">
                <h3 class="font-semibold text-amber-950 dark:text-amber-100">Filas que no fueron cargadas</h3>
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                    La importación terminó correctamente, pero estas filas fueron omitidas por contener información incompleta o inválida.
                    Mostrando {{ count($importSkippedRows) }} de {{ $importSkippedCount }}.
                </p>
            </div>

            <div class="max-h-80 overflow-auto border-t border-amber-200 dark:border-amber-400/20">
                <table class="w-full min-w-4xl text-left text-sm">
                    <thead class="sticky top-0 bg-amber-100 text-xs uppercase tracking-wider text-amber-700 dark:bg-amber-950 dark:text-amber-200">
                        <tr>
                            <th class="px-4 py-3">Fila del Excel</th>
                            <th class="px-4 py-3">Motivo</th>
                            <th class="px-4 py-3">Valores leídos</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100 bg-white dark:divide-amber-400/10 dark:bg-slate-950/60">
                        @foreach ($importSkippedRows as $index => $skippedRow)
                            <tr wire:key="excel-skipped-row-{{ $index }}">
                                <td class="whitespace-nowrap px-4 py-3 font-semibold">{{ $skippedRow['row'] }}</td>
                                <td class="min-w-72 px-4 py-3 font-medium text-amber-800 dark:text-amber-200">{{ $skippedRow['reason'] }}</td>
                                <td class="min-w-96 px-4 py-3 text-slate-600 dark:text-slate-300">
                                    {{ collect($skippedRow['values'])->map(fn ($value, $column) => ['NO', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Año', 'NIV', 'Código'][$column].': '.($value !== '' ? $value : 'vacío'))->implode(' · ') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if (session('deletionResult'))
        <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-500/10 dark:text-emerald-200">
            {{ session('deletionResult') }}
        </div>
    @endif

    @error('importFile')
        <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800 dark:border-rose-400/20 dark:bg-rose-500/10 dark:text-rose-200">
            {{ $message }}
        </div>
    @enderror

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 lg:flex-row lg:items-end">
            <label for="certificate-search" class="sr-only">Buscar certificados</label>
            <div class="relative w-full max-w-2xl">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/>
                </svg>
                <input
                    id="certificate-search"
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Buscar por NO, marca, modelo, NIV o código..."
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-12 pr-4 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white"
                >
                <span wire:loading wire:target="search" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-medium text-indigo-500">
                    Buscando...
                </span>
            </div>

            <div class="w-full lg:max-w-xs">
                <label for="certificate-record-filter" class="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    Filtrar registros
                </label>
                <select
                    id="certificate-record-filter"
                    wire:model.live="recordFilter"
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white"
                >
                    <option value="all">Todos los registros</option>
                    <option value="duplicates">NIV duplicados</option>
                    <option value="invalid_niv_length">NIV con longitud inválida</option>
                    <option value="invalid_records">Registros inválidos</option>
                </select>
            </div>

            <x-per-page-selector id="certificates-per-page" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-7xl text-left">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-4 font-semibold">NO</th>
                        <th class="px-5 py-4 font-semibold">Marca</th>
                        <th class="px-5 py-4 font-semibold">Modelo</th>
                        <th class="px-5 py-4 font-semibold">Tipo</th>
                        <th class="px-5 py-4 font-semibold">Fabricación</th>
                        <th class="px-5 py-4 font-semibold">Año</th>
                        <th class="px-5 py-4 font-semibold">NIV</th>
                        <th class="px-5 py-4 font-semibold">No Certificado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->certificates as $certificate)
                        <tr wire:key="certificate-{{ $certificate->id }}" class="transition hover:bg-slate-50 dark:hover:bg-white/[0.025]">
                            <td class="whitespace-nowrap px-5 py-4 font-semibold text-indigo-600 dark:text-indigo-300">{{ $certificate->no }}</td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $certificate->marca }}</td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $certificate->modelo }}</td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $certificate->tipo }}</td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $certificate->fabricacion }}</td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $certificate->anio }}</td>
                            <td class="whitespace-nowrap px-5 py-4 font-mono text-sm text-slate-600 dark:text-slate-300">{{ $certificate->niv }}</td>
                            <td class="whitespace-nowrap px-5 py-4 font-mono text-sm text-slate-600 dark:text-slate-300">{{ $certificate->codigo }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-14 text-center">
                                <p class="font-semibold">No se encontraron certificados</p>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    No existen registros que coincidan con la búsqueda.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->certificates->hasPages())
            <div class="border-t border-slate-200 px-6 py-4 dark:border-white/10">
                {{ $this->certificates->links() }}
            </div>
        @endif
    </div>

    @if ($showDeleteConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-certificates-title">
            <button wire:click="closeDeleteConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar eliminación"></button>

            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-6 shadow-2xl dark:border-rose-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.5 2.7 18a2 2 0 0 0 1.74 3h15.12a2 2 0 0 0 1.74-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/>
                        </svg>
                    </div>

                    <h3 id="delete-certificates-title" class="mt-5 text-2xl font-semibold">¿Estás seguro de que quieres eliminar?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">
                        Se eliminarán <strong>{{ $deleteCount }} registros</strong>
                        @if ($deleteSearch !== '')
                            encontrados con la búsqueda “{{ $deleteSearch }}”.
                        @elseif ($deleteRecordFilter === 'duplicates')
                            identificados por el filtro de NIV duplicados.
                        @elseif ($deleteRecordFilter === 'invalid_niv_length')
                            identificados por tener un NIV con menos o más de 17 caracteres.
                        @elseif ($deleteRecordFilter === 'invalid_records')
                            identificados por contener uno o más campos inválidos.
                        @else
                            del maestro completo.
                        @endif
                        Esta acción no se puede deshacer.
                    </p>

                    <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button wire:click="closeDeleteConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">
                            Cancelar
                        </button>
                        <button wire:click="deleteRecords" wire:loading.attr="disabled" wire:target="deleteRecords" type="button" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60 dark:bg-rose-500 dark:hover:bg-rose-400">
                            <span wire:loading.remove wire:target="deleteRecords">Sí, eliminar</span>
                            <span wire:loading wire:target="deleteRecords">Eliminando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
