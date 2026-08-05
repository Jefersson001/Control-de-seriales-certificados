<?php

use App\Actions\Certificates\ImportCertificatesFromPdf;
use App\UserPermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new class extends Component
{
    use WithFileUploads;

    public $pdfFile;

    public bool $showPreview = false;

    #[Locked]
    public ?string $previewToken = null;

    /** @var array<int, array<string, int|string>> */
    public array $previewRows = [];

    public string $controlNumber = '';

    public int $validCount = 0;

    public int $duplicateCount = 0;

    /** @var array<int, array{no: string, niv: string, values: array<int, string>, reason: string}> */
    public array $duplicateRows = [];

    public int $invalidCount = 0;

    public bool $includeReady = true;

    public bool $includeDuplicates = false;

    public bool $includeInvalid = false;

    /** @var array<int, array{page: int|null, no: string, niv: string, values: array<int, string>, reason: string}> */
    public array $invalidRows = [];

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function analyzePdf(ImportCertificatesFromPdf $pdfImporter): void
    {
        $this->authorizeAccess();

        $this->validate([
            'pdfFile' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480'],
        ], [
            'pdfFile.required' => 'Selecciona el PDF que deseas analizar.',
            'pdfFile.mimes' => 'El archivo debe tener formato PDF.',
            'pdfFile.mimetypes' => 'El contenido del archivo debe ser un PDF válido.',
            'pdfFile.max' => 'El PDF no puede superar los 20 MB.',
        ]);

        try {
            $result = $pdfImporter->parse($this->pdfFile->getRealPath());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('pdfFile', $exception->getMessage());

            return;
        }

        $this->forgetPreview();
        $this->previewToken = Str::random(40);
        Cache::put($this->cacheKey(), $result, now()->addMinutes(30));
        $this->previewRows = array_slice($result['records'], 0, 10);
        $this->controlNumber = $result['controlNumber'];
        $this->validCount = count($result['records']);
        $this->duplicateCount = $result['duplicateCount'];
        $this->duplicateRows = $result['duplicateRows'];
        $this->invalidCount = $result['invalidCount'];
        $this->invalidRows = $result['invalidRows'];
        $this->showPreview = true;
    }

    public function confirmImport(ImportCertificatesFromPdf $pdfImporter): void
    {
        $this->authorizeAccess();

        $this->validate([
            'includeReady' => ['boolean'],
            'includeDuplicates' => ['boolean'],
            'includeInvalid' => ['boolean'],
        ]);

        if (! $this->includeReady && ! $this->includeDuplicates && ! $this->includeInvalid) {
            $this->addError('pdfFile', 'Selecciona al menos una categoría para importar.');

            return;
        }

        $analysis = Cache::pull($this->cacheKey());

        if (! is_array($analysis) || ! isset($analysis['records'])) {
            $this->addError('pdfFile', 'La vista previa venció. Analiza nuevamente el PDF.');
            $this->resetPreview();

            return;
        }

        $result = $pdfImporter->storeSelection(
            $analysis,
            $this->includeReady,
            $this->includeDuplicates,
            $this->includeInvalid,
        );

        $this->reset(['pdfFile']);
        $this->resetPreview();

        session()->flash(
            'pdfImportResult',
            "PDF procesado: {$result['imported']} registros cargados ({$result['ready']} listos, {$result['duplicates']} duplicados y {$result['invalid']} inválidos). {$result['skipped']} listos fueron omitidos porque su NIV ya existía al confirmar.",
        );
    }

    public function cancelImport(): void
    {
        $this->forgetPreview();
        $this->reset(['pdfFile']);
        $this->resetPreview();
    }

    public function exportAnalysis(
        string $category,
        App\Actions\Certificates\ExportPdfAnalysis $exportPdfAnalysis,
    ): ?BinaryFileResponse {
        $this->authorizeAccess();

        if (! in_array($category, ['ready', 'duplicates', 'invalid'], true)) {
            abort(404);
        }

        $analysis = Cache::get($this->cacheKey());

        if (! is_array($analysis)) {
            $this->addError('pdfFile', 'La vista previa venció. Analiza nuevamente el PDF.');

            return null;
        }

        try {
            return $exportPdfAnalysis->handle($category, $analysis);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('pdfFile', $exception->getMessage());

            return null;
        }
    }

    public function selectedImportCount(): int
    {
        return ($this->includeReady ? $this->validCount : 0)
            + ($this->includeDuplicates ? $this->duplicateCount : 0)
            + ($this->includeInvalid ? $this->invalidCount : 0);
    }

    private function authorizeAccess(): void
    {
        abort_unless(
            auth()->user()?->hasPermission(UserPermission::ViewVehicleIdentificationRecord),
            403,
        );
    }

    private function cacheKey(): string
    {
        return 'vehicle-identification-record-preview:'.auth()->id().':'.$this->previewToken;
    }

    private function forgetPreview(): void
    {
        if ($this->previewToken !== null) {
            Cache::forget($this->cacheKey());
        }
    }

    private function resetPreview(): void
    {
        $this->reset([
            'showPreview',
            'previewToken',
            'previewRows',
            'controlNumber',
            'validCount',
            'duplicateCount',
            'duplicateRows',
            'invalidCount',
            'invalidRows',
            'includeReady',
            'includeDuplicates',
            'includeInvalid',
        ]);
    }
};
?>

<div class="grid gap-6">
    <section class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.04] sm:p-8">
        <div class="absolute -right-20 -top-20 size-64 rounded-full bg-sky-500/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-600 dark:text-sky-300">Carga de constancia</p>
                <h2 class="mt-3 text-3xl font-semibold tracking-tight">Importar registros desde PDF</h2>
                <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">
                    Selecciona una constancia. El sistema extraerá el número de control y los vehículos, verificará los NIV duplicados y mostrará una vista previa antes de guardar.
                </p>
            </div>

            <label class="inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-xl bg-sky-600 px-5 py-3 font-semibold text-white shadow-sm transition hover:bg-sky-700 dark:bg-sky-400 dark:text-slate-950 dark:hover:bg-sky-300">
                <input wire:model="pdfFile" type="file" accept=".pdf,application/pdf" class="sr-only">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0-4 4m4-4 4 4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/>
                </svg>
                Seleccionar PDF
            </label>
        </div>
    </section>

    @if ($pdfFile && ! $showPreview)
        <section class="flex flex-col gap-4 rounded-3xl border border-sky-200 bg-sky-50 p-5 dark:border-sky-400/20 dark:bg-sky-500/10 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="font-semibold text-sky-950 dark:text-sky-100">{{ $pdfFile->getClientOriginalName() }}</p>
                <p class="mt-1 text-sm text-sky-700 dark:text-sky-300">El análisis no guardará información todavía.</p>
            </div>
            <button wire:click="analyzePdf" wire:loading.attr="disabled" wire:target="analyzePdf,pdfFile" type="button" class="rounded-xl bg-sky-600 px-5 py-3 font-semibold text-white transition hover:bg-sky-700 disabled:cursor-wait disabled:opacity-60 dark:bg-sky-400 dark:text-slate-950 dark:hover:bg-sky-300">
                <span wire:loading.remove wire:target="analyzePdf">Analizar PDF</span>
                <span wire:loading wire:target="analyzePdf">Analizando...</span>
            </button>
        </section>
    @endif

    @error('pdfFile')
        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800 dark:border-rose-400/20 dark:bg-rose-500/10 dark:text-rose-200">
            {{ $message }}
        </div>
    @enderror

    @if (session('pdfImportResult'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-500/10 dark:text-emerald-200">
            {{ session('pdfImportResult') }}
        </div>
    @endif

    @if ($showPreview)
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
            <div class="flex flex-col gap-3 border-b border-slate-200 p-6 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-600 dark:text-sky-300">Vista previa del PDF</p>
                    <h3 class="mt-2 text-2xl font-semibold">Número de control {{ $controlNumber }}</h3>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Se muestran los primeros 10 registros válidos.</p>
                </div>
                <button wire:click="cancelImport" type="button" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">
                    Cancelar
                </button>
            </div>

            <div class="grid gap-3 p-6 sm:grid-cols-3">
                <div class="rounded-2xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                    <p class="text-sm text-emerald-700 dark:text-emerald-300">Listos para importar</p>
                    <p class="mt-1 text-2xl font-semibold text-emerald-950 dark:text-emerald-100">{{ $validCount }}</p>
                    <button wire:click="exportAnalysis('ready')" wire:loading.attr="disabled" type="button" @disabled($validCount === 0) class="mt-3 rounded-lg border border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-400/30 dark:text-emerald-200 dark:hover:bg-emerald-500/10">
                        Exportar listos
                    </button>
                    <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold text-emerald-900 dark:text-emerald-100">
                        <input wire:model.live="includeReady" type="checkbox" @disabled($validCount === 0) class="size-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500">
                        Incluir al importar
                    </label>
                </div>
                <div class="rounded-2xl bg-amber-50 p-4 dark:bg-amber-500/10">
                    <p class="text-sm text-amber-700 dark:text-amber-300">NIV duplicados omitidos</p>
                    <p class="mt-1 text-2xl font-semibold text-amber-950 dark:text-amber-100">{{ $duplicateCount }}</p>
                    <button wire:click="exportAnalysis('duplicates')" wire:loading.attr="disabled" type="button" @disabled($duplicateCount === 0) class="mt-3 rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-400/30 dark:text-amber-200 dark:hover:bg-amber-500/10">
                        Exportar duplicados
                    </button>
                    <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-100">
                        <input wire:model.live="includeDuplicates" type="checkbox" @disabled($duplicateCount === 0) class="size-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                        Incluir al importar
                    </label>
                </div>
                <div class="rounded-2xl bg-rose-50 p-4 dark:bg-rose-500/10">
                    <p class="text-sm text-rose-700 dark:text-rose-300">Filas inválidas</p>
                    <p class="mt-1 text-2xl font-semibold text-rose-950 dark:text-rose-100">{{ $invalidCount }}</p>
                    <button wire:click="exportAnalysis('invalid')" wire:loading.attr="disabled" type="button" @disabled($invalidCount === 0) class="mt-3 rounded-lg border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-400/30 dark:text-rose-200 dark:hover:bg-rose-500/10">
                        Exportar inválidos
                    </button>
                    <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold text-rose-900 dark:text-rose-100">
                        <input wire:model.live="includeInvalid" type="checkbox" @disabled($invalidCount === 0) class="size-4 rounded border-rose-300 text-rose-600 focus:ring-rose-500">
                        Incluir al importar
                    </label>
                </div>
            </div>

            @if ($includeInvalid && $invalidCount > 0)
                <div class="mx-6 mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-400/20 dark:bg-rose-500/10 dark:text-rose-200">
                    Los inválidos se guardarán bajo tu autorización. Los campos faltantes quedarán vacíos y un Año no numérico se almacenará como 0.
                </div>
            @endif

            @if ($duplicateRows !== [])
                <div class="border-t border-amber-200 bg-amber-50/70 p-6 dark:border-amber-400/20 dark:bg-amber-500/5">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h4 class="font-semibold text-amber-950 dark:text-amber-100">Detalle de NIV duplicados</h4>
                            <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                                Estos registros no se importarán porque el NIV está repetido en el PDF o ya fue registrado anteriormente.
                            </p>
                        </div>
                        <span class="text-sm font-medium text-amber-700 dark:text-amber-300">
                            {{ count($duplicateRows) }} {{ Str::plural('duplicado mostrado', count($duplicateRows)) }}
                        </span>
                    </div>

                    <div class="mt-4 max-h-80 overflow-auto rounded-2xl border border-amber-200 bg-white dark:border-amber-400/20 dark:bg-slate-950/60">
                        <table class="w-full min-w-3xl text-left text-sm">
                            <thead class="sticky top-0 bg-amber-100 text-xs uppercase tracking-wider text-amber-700 dark:bg-amber-950 dark:text-amber-200">
                                <tr>
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">NIV</th>
                                    <th class="px-4 py-3">Origen del duplicado</th>
                                    <th class="px-4 py-3">Valores leídos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-amber-100 dark:divide-amber-400/10">
                                @foreach ($duplicateRows as $index => $duplicateRow)
                                    <tr wire:key="duplicate-pdf-row-{{ $index }}">
                                        <td class="whitespace-nowrap px-4 py-3 font-semibold">{{ $duplicateRow['no'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 font-mono">{{ $duplicateRow['niv'] }}</td>
                                        <td class="min-w-72 px-4 py-3 font-medium text-amber-800 dark:text-amber-200">{{ $duplicateRow['reason'] }}</td>
                                        <td class="min-w-96 px-4 py-3 text-slate-600 dark:text-slate-300">
                                            {{ collect($duplicateRow['values'])->map(fn ($value, $column) => ['NO', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Año', 'NIV'][$column].': '.$value)->implode(' · ') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($invalidRows !== [])
                <div class="border-t border-rose-200 bg-rose-50/70 p-6 dark:border-rose-400/20 dark:bg-rose-500/5">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h4 class="font-semibold text-rose-950 dark:text-rose-100">Detalle de filas inválidas</h4>
                            <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">
                                Estos registros no se importarán. Revisa el motivo y los valores obtenidos del PDF.
                            </p>
                        </div>
                        <span class="text-sm font-medium text-rose-700 dark:text-rose-300">
                            {{ count($invalidRows) }} {{ Str::plural('fila mostrada', count($invalidRows)) }}
                        </span>
                    </div>

                    <div class="mt-4 max-h-80 overflow-auto rounded-2xl border border-rose-200 bg-white dark:border-rose-400/20 dark:bg-slate-950/60">
                        <table class="w-full min-w-3xl text-left text-sm">
                            <thead class="sticky top-0 bg-rose-100 text-xs uppercase tracking-wider text-rose-700 dark:bg-rose-950 dark:text-rose-200">
                                <tr>
                                    <th class="px-4 py-3">Página</th>
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">NIV detectado</th>
                                    <th class="px-4 py-3">Motivo</th>
                                    <th class="px-4 py-3">Valores leídos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-rose-100 dark:divide-rose-400/10">
                                @foreach ($invalidRows as $index => $invalidRow)
                                    <tr wire:key="invalid-pdf-row-{{ $index }}">
                                        <td class="whitespace-nowrap px-4 py-3">{{ $invalidRow['page'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 font-semibold">{{ $invalidRow['no'] ?: '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 font-mono">{{ $invalidRow['niv'] ?: 'No detectado' }}</td>
                                        <td class="min-w-72 px-4 py-3 font-medium text-rose-800 dark:text-rose-200">{{ $invalidRow['reason'] }}</td>
                                        <td class="min-w-96 px-4 py-3 text-slate-600 dark:text-slate-300">
                                            {{ collect($invalidRow['values'])->map(fn ($value, $column) => ['NO', 'Marca', 'Modelo', 'Tipo', 'Fabricación', 'Año', 'NIV'][$column].': '.($value ?: 'vacío'))->implode(' · ') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="max-h-[50vh] overflow-auto border-y border-slate-200 dark:border-white/10">
                <table class="w-full min-w-5xl text-left text-sm">
                    <thead class="sticky top-0 bg-slate-100 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3">Marca</th>
                            <th class="px-4 py-3">Modelo</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Fabricación</th>
                            <th class="px-4 py-3">Año</th>
                            <th class="px-4 py-3">NIV</th>
                            <th class="px-4 py-3">Código</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @forelse ($previewRows as $record)
                            <tr wire:key="constancia-preview-{{ $record['niv'] }}">
                                <td class="whitespace-nowrap px-4 py-3 font-semibold">{{ $record['no'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $record['marca'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $record['modelo'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $record['tipo'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $record['fabricacion'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $record['anio'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono">{{ $record['niv'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono">{{ $record['codigo'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-10 text-center text-slate-500 dark:text-slate-400">Todos los NIV del PDF ya están registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col-reverse gap-3 p-6 sm:flex-row sm:justify-end">
                <button wire:click="cancelImport" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">
                    Cancelar
                </button>
                <button wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" type="button" @disabled($this->selectedImportCount() === 0) class="rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400">
                    <span wire:loading.remove wire:target="confirmImport">Confirmar e importar {{ $this->selectedImportCount() }}</span>
                    <span wire:loading wire:target="confirmImport">Importando...</span>
                </button>
            </div>
        </section>
    @endif
</div>
