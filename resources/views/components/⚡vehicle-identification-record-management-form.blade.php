<?php

use App\Actions\VehicleIdentificationRecords\ProcessManagementCertificates;
use App\Actions\VehicleIdentificationRecords\DeleteManagementCertificate;
use App\Actions\VehicleIdentificationRecords\ExportManagementCertificateAnalysis;
use App\Actions\VehicleIdentificationRecords\ImportManagementCertificateAnalysis;
use App\Models\VehicleIdentificationRecordManagement;
use App\Models\VehicleIdentificationRecordManagementCertificate;
use App\UserPermission;
use App\VehicleIdentificationRecordManagementStatus;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $managementId;

    public string $status = VehicleIdentificationRecordManagementStatus::Draft->value;

    #[Locked]
    public bool $persistedDone = false;

    /** @var array<int, mixed> */
    public array $pdfFiles = [];

    public bool $showPdfAnalysis = false;

    public bool $showDeleteCertificateConfirmation = false;

    #[Locked]
    public ?int $certificatePendingDeletionId = null;

    /** @var list<array{id: int, control_number: string, original_file_name: string, analyzed_at: string|null, can_delete: bool}> */
    #[Locked]
    public array $certificates = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $matchedSerials = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $duplicateSerials = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $unexpectedSerials = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $missingSerials = [];

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $invalidPdfRows = [];

    #[Locked]
    public int $expectedSerialCount = 0;

    #[Locked]
    public int $pdfOccurrenceCount = 0;

    #[Locked]
    public bool $exactPdfMatch = false;

    public bool $includeCertified = true;

    public bool $includeDuplicates = false;

    public bool $includeUnexpected = false;

    public bool $includeMissing = false;

    public bool $includeInvalid = false;

    public function mount(int $managementId): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewVehicleIdentificationRecordManagement), 403);

        $this->managementId = $managementId;
        $management = VehicleIdentificationRecordManagement::query()->findOrFail($managementId);
        $this->status = $management->status->value;
        $this->persistedDone = $management->status === VehicleIdentificationRecordManagementStatus::Done;

        if ($management->certificates()->exists()) {
            $this->applyCertificateSummary(app(ProcessManagementCertificates::class)->summary($management));
        }
    }

    #[Computed]
    public function management(): VehicleIdentificationRecordManagement
    {
        return VehicleIdentificationRecordManagement::query()
            ->with([
                'motorcycleSerialRequest.lines:id,motorcycle_serial_request_id,quantity',
                'motorcycleSerialRequest.lines.serialEntries:id,motorcycle_serial_request_line_id,serial',
                'motorcycleSerialRequest.user:id,name',
            ])
            ->findOrFail($this->managementId);
    }

    /** @return array<int, VehicleIdentificationRecordManagementStatus> */
    public function statuses(): array
    {
        return VehicleIdentificationRecordManagementStatus::cases();
    }

    public function canEdit(): bool
    {
        return ! $this->persistedDone
            && auth()->user()?->hasPermission(UserPermission::EditVehicleIdentificationRecordManagement) === true;
    }

    public function analyzePdf(ProcessManagementCertificates $processor): void
    {
        abort_unless($this->canEdit(), 403);

        $this->validate([
            'pdfFiles' => ['required', 'array', 'min:1', 'max:20'],
            'pdfFiles.*' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480'],
        ], [
            'pdfFiles.required' => 'Selecciona al menos un PDF.',
            'pdfFiles.min' => 'Selecciona al menos un PDF.',
            'pdfFiles.max' => 'Puedes procesar un máximo de 20 PDF a la vez.',
            'pdfFiles.*.mimes' => 'Todos los archivos deben tener formato PDF.',
            'pdfFiles.*.mimetypes' => 'Todos los archivos deben ser PDF válidos.',
            'pdfFiles.*.max' => 'Cada PDF puede tener un tamaño máximo de 20 MB.',
        ]);

        try {
            $result = $processor->handle($this->management, $this->pdfFiles);
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('pdfFiles', $exception->getMessage());

            return;
        }

        $this->applyCertificateSummary($result);
        $this->reset('pdfFiles');
        $this->showPdfAnalysis = true;
        unset($this->management);
    }

    public function processNextPdf(ProcessManagementCertificates $processor): bool
    {
        abort_unless($this->canEdit(), 403);

        $this->validate([
            'pdfFiles' => ['required', 'array', 'min:1', 'max:20'],
            'pdfFiles.*' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480'],
        ], [
            'pdfFiles.required' => 'Selecciona al menos un PDF.',
            'pdfFiles.min' => 'Selecciona al menos un PDF.',
            'pdfFiles.max' => 'Puedes procesar un máximo de 20 PDF a la vez.',
            'pdfFiles.*.mimes' => 'Todos los archivos deben tener formato PDF.',
            'pdfFiles.*.mimetypes' => 'Todos los archivos deben ser PDF válidos.',
            'pdfFiles.*.max' => 'Cada PDF puede tener un tamaño máximo de 20 MB.',
        ]);

        $file = array_shift($this->pdfFiles);

        try {
            $result = $processor->handle($this->management, [$file]);
        } catch (\Throwable $exception) {
            report($exception);
            array_unshift($this->pdfFiles, $file);
            $this->addError('pdfFiles', $exception->getMessage());

            return false;
        }

        $this->applyCertificateSummary($result);
        $this->showPdfAnalysis = true;
        unset($this->management);

        if ($this->pdfFiles === []) {
            $this->reset('pdfFiles');
        }

        return $this->pdfFiles !== [];
    }

    public function cancelPdfAnalysis(): void
    {
        $this->reset(['pdfFiles', 'showPdfAnalysis']);
        $this->resetErrorBag('pdfFiles');
    }

    public function openPdfAnalysis(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewVehicleIdentificationRecordManagement), 403);

        if ($this->certificates !== []) {
            $this->showPdfAnalysis = true;
        }
    }

    public function exportCertificateAnalysis(
        string $category,
        ExportManagementCertificateAnalysis $exporter,
    ): ?BinaryFileResponse {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewVehicleIdentificationRecordManagement), 403);
        abort_unless(in_array($category, ['certified', 'duplicates', 'unexpected', 'missing', 'invalid'], true), 404);

        try {
            return $exporter->handle($this->management, $category);
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('certificateSelection', $exception->getMessage());

            return null;
        }
    }

    public function importCertificateSelection(
        ImportManagementCertificateAnalysis $importer,
        ProcessManagementCertificates $processor,
    ): void {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ImportCertificates), 403);
        abort_if($this->persistedDone, 403);

        $this->validate([
            'includeCertified' => ['boolean'],
            'includeDuplicates' => ['boolean'],
            'includeUnexpected' => ['boolean'],
            'includeMissing' => ['boolean'],
            'includeInvalid' => ['boolean'],
        ]);

        if (! $this->includeCertified && ! $this->includeDuplicates && ! $this->includeUnexpected && ! $this->includeMissing && ! $this->includeInvalid) {
            $this->addError('certificateSelection', 'Selecciona al menos una categoría para importar.');

            return;
        }

        $result = $importer->handle(
            $this->management,
            $this->includeCertified,
            $this->includeDuplicates,
            $this->includeInvalid,
            $this->includeUnexpected,
            $this->includeMissing,
        );
        $this->status = $result['status'];
        $this->persistedDone = $result['status'] === VehicleIdentificationRecordManagementStatus::Done->value;
        unset($this->management);
        $this->applyCertificateSummary($processor->summary($this->management));

        $managementStatusMessage = $this->persistedDone
            ? 'La gestión fue completada automáticamente.'
            : 'La gestión continúa parcialmente en proceso y admite nuevos certificados.';

        session()->flash(
            'managementCertificateImportResult',
            "Se importaron {$result['imported']} registros al maestro ({$result['certified']} certificados, {$result['duplicates']} duplicados, {$result['unexpected']} no solicitados, {$result['missing']} sin certificar y {$result['invalid']} inválidos). {$result['skipped']} registros fueron omitidos. {$result['imported_requested']} de {$result['expected_requested']} seriales solicitados están certificados e importados. {$managementStatusMessage}",
        );
    }

    public function selectedCertificateImportCount(): int
    {
        return ($this->includeCertified ? collect($this->matchedSerials)->where('imported', false)->count() : 0)
            + ($this->includeDuplicates ? collect($this->duplicateSerials)->where('imported', false)->sum('occurrences') : 0)
            + ($this->includeUnexpected ? collect($this->unexpectedSerials)->where('imported', false)->sum('occurrences') : 0)
            + ($this->includeMissing ? count($this->missingSerials) : 0)
            + ($this->includeInvalid ? collect($this->invalidPdfRows)->where('imported', false)->sum('occurrences') : 0);
    }

    public function downloadCertificate(int $certificateId): mixed
    {
        $certificate = VehicleIdentificationRecordManagementCertificate::query()
            ->where('management_id', $this->managementId)
            ->findOrFail($certificateId);
        abort_unless(Storage::disk('local')->exists($certificate->file_path), 404);

        return Storage::disk('local')->download($certificate->file_path, $certificate->original_file_name);
    }

    public function openDeleteCertificateConfirmation(int $certificateId): void
    {
        abort_unless($this->canEdit(), 403);

        VehicleIdentificationRecordManagementCertificate::query()
            ->where('management_id', $this->managementId)
            ->whereDoesntHave('serialResults', fn ($query) => $query->whereNotNull('imported_at'))
            ->findOrFail($certificateId);

        $this->certificatePendingDeletionId = $certificateId;
        $this->showDeleteCertificateConfirmation = true;
    }

    public function closeDeleteCertificateConfirmation(): void
    {
        $this->certificatePendingDeletionId = null;
        $this->showDeleteCertificateConfirmation = false;
    }

    public function deleteCertificate(
        DeleteManagementCertificate $deleter,
        ProcessManagementCertificates $processor,
    ): void {
        abort_unless($this->canEdit(), 403);
        abort_if($this->certificatePendingDeletionId === null, 422);

        $certificate = VehicleIdentificationRecordManagementCertificate::query()
            ->where('management_id', $this->managementId)
            ->whereDoesntHave('serialResults', fn ($query) => $query->whereNotNull('imported_at'))
            ->findOrFail($this->certificatePendingDeletionId);

        $deleter->handle($certificate);
        $this->closeDeleteCertificateConfirmation();
        $this->applyCertificateSummary($processor->summary($this->management));
        $this->showPdfAnalysis = $this->certificates !== [];
    }

    public function setStatus(string $status): void
    {
        abort_unless($this->canEdit(), 403);

        $newStatus = VehicleIdentificationRecordManagementStatus::tryFrom($status);
        abort_if($newStatus === null || $newStatus === VehicleIdentificationRecordManagementStatus::Done, 422);

        $this->status = $newStatus->value;
    }

    public function save(): mixed
    {
        abort_unless($this->canEdit(), 403);
        $validated = $this->validate([
            'status' => ['required', Rule::enum(VehicleIdentificationRecordManagementStatus::class)],
        ]);
        abort_if($validated['status'] === VehicleIdentificationRecordManagementStatus::Done->value, 422);

        VehicleIdentificationRecordManagement::query()->findOrFail($this->managementId)->update($validated);

        return redirect()
            ->route('vehicle_identification_record_management.edit', $this->managementId)
            ->with('status', 'Gestión actualizada correctamente.');
    }

    /** @param array<string, mixed> $summary */
    private function applyCertificateSummary(array $summary): void
    {
        $this->certificates = $summary['certificates'];
        $this->expectedSerialCount = $summary['expectedCount'];
        $this->pdfOccurrenceCount = $summary['pdfOccurrenceCount'];
        $this->matchedSerials = $summary['matchedSerials'];
        $this->duplicateSerials = $summary['duplicateSerials'];
        $this->unexpectedSerials = $summary['unexpectedSerials'];
        $this->missingSerials = $summary['missingSerials'];
        $this->invalidPdfRows = $summary['invalidRows'];
        $this->exactPdfMatch = $summary['exactMatch'];
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('vehicle_identification_record_management.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                Volver a la lista
            </a>
            <span class="text-sm text-slate-500 dark:text-slate-400">Gestión #{{ $managementId }}</span>
        </div>

        <div class="flex items-center gap-3">
            @if ($this->canEdit())
                <button wire:click="save" wire:loading.attr="disabled" wire:target="save" type="button" class="rounded-xl bg-violet-600 px-6 py-3 font-semibold text-white shadow-lg shadow-violet-500/25 transition hover:bg-violet-500 disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">Guardar</span>
                    <span wire:loading wire:target="save">Guardando...</span>
                </button>
            @else
                <span class="rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">Solo lectura</span>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
    @endif

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="border-b border-slate-200 bg-slate-50/70 px-6 py-6 dark:border-white/10 dark:bg-slate-950/30 sm:px-8">
            <p class="mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Estado de la gestión</p>
            <div class="grid grid-cols-3 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                @foreach ($this->statuses() as $availableStatus)
                    <button
                        wire:key="management-status-{{ $availableStatus->value }}"
                        @if ($availableStatus !== VehicleIdentificationRecordManagementStatus::Done)
                            wire:click="setStatus('{{ $availableStatus->value }}')"
                        @else
                            title="El estado Hecho se asigna automáticamente al importar al maestro"
                        @endif
                        type="button"
                        @disabled(! $this->canEdit() || $availableStatus === VehicleIdentificationRecordManagementStatus::Done)
                        @class([
                            'relative px-3 py-3 text-sm font-semibold transition sm:px-5',
                            'border-l border-slate-200 dark:border-white/10' => ! $loop->first,
                            'bg-slate-700 text-white dark:bg-slate-600' => $status === $availableStatus->value && $availableStatus === VehicleIdentificationRecordManagementStatus::Draft,
                            'bg-amber-500 text-white' => $status === $availableStatus->value && $availableStatus === VehicleIdentificationRecordManagementStatus::InProgress,
                            'bg-emerald-600 text-white' => $status === $availableStatus->value && $availableStatus === VehicleIdentificationRecordManagementStatus::Done,
                            'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/5' => $status !== $availableStatus->value && $this->canEdit() && $availableStatus !== VehicleIdentificationRecordManagementStatus::Done,
                            'cursor-not-allowed text-slate-400' => $status !== $availableStatus->value && (! $this->canEdit() || $availableStatus === VehicleIdentificationRecordManagementStatus::Done),
                        ])
                    >{{ $availableStatus->label() }}</button>
                @endforeach
            </div>
        </div>

        <div class="p-6 sm:p-8">
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-slate-950/30">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Solicitud relacionada</p>
                    @if (auth()->user()?->hasPermission(UserPermission::ViewMotorcycleSerialRequests))
                        <a href="{{ route('motorcycle_serial_requests.edit', $this->management->motorcycleSerialRequest) }}" class="mt-2 inline-flex font-semibold text-violet-700 hover:text-violet-500 dark:text-violet-300">Solicitud #{{ $this->management->motorcycle_serial_request_id }}</a>
                    @else
                        <p class="mt-2 font-semibold">Solicitud #{{ $this->management->motorcycle_serial_request_id }}</p>
                    @endif
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-slate-950/30">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Fecha de creación</p>
                    <p class="mt-2 font-semibold">{{ $this->management->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-slate-950/30">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Creado por</p>
                    <p class="mt-2 font-semibold">{{ $this->management->motorcycleSerialRequest->user?->name ?? 'Usuario no disponible' }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-slate-950/30">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Total de seriales</p>
                    <p class="mt-2 text-2xl font-semibold">{{ $this->management->motorcycleSerialRequest->lines->sum(fn ($line) => $line->serialEntries->count()) }}</p>
                </div>
            </div>

            <section x-data="{ processing: false, total: 0, completed: 0, progress: 0, progressTimer: null, startProgress() { this.stopProgress(); this.progressTimer = setInterval(() => { const limit = ((this.completed + 0.99) / this.total) * 100; if (this.progress < limit) { this.progress = Math.min(limit, this.progress + Math.max(0.2, (limit - this.progress) * 0.025)); } }, 400); }, stopProgress() { if (this.progressTimer !== null) { clearInterval(this.progressTimer); this.progressTimer = null; } }, async processCertificates() { this.processing = true; this.total = $wire.pdfFiles.length; this.completed = 0; this.progress = 0; try { let hasMore = true; while (hasMore) { this.startProgress(); hasMore = await $wire.processNextPdf(); this.stopProgress(); this.completed++; this.progress = Math.round((this.completed / this.total) * 100); } await new Promise(resolve => setTimeout(resolve, 700)); } finally { this.stopProgress(); this.processing = false; } } }" class="mt-8 overflow-hidden rounded-3xl border border-sky-200 bg-sky-50/60 dark:border-sky-500/20 dark:bg-sky-500/5">
                <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-center sm:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-600 dark:text-sky-300">Comparación de certificación</p>
                        <h3 class="mt-2 text-2xl font-semibold">Validar seriales contra un PDF</h3>
                        <p class="mt-2 leading-7 text-slate-600 dark:text-slate-300">El PDF se comparará únicamente con los seriales de la solicitud #{{ $this->management->motorcycle_serial_request_id }}. Este análisis no importa registros ni consulta el Maestro de Seriales Certificados.</p>
                    </div>

                    @if ($this->canEdit())
                        <label class="inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-xl bg-sky-600 px-5 py-3 font-semibold text-white shadow-sm transition hover:bg-sky-700 dark:bg-sky-400 dark:text-slate-950 dark:hover:bg-sky-300">
                            <input wire:key="certificate-pdf-input-{{ count($certificates) }}" wire:model="pdfFiles" type="file" accept=".pdf,application/pdf" multiple class="sr-only">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0-4 4m4-4 4 4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/></svg>
                            Seleccionar PDF
                        </label>
                    @endif
                </div>

                @if ($pdfFiles !== [])
                    <div class="border-t border-sky-200 bg-white/70 p-5 dark:border-sky-500/20 dark:bg-slate-900/40">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-semibold text-sky-950 dark:text-sky-100">{{ count($pdfFiles) }} {{ count($pdfFiles) === 1 ? 'certificado seleccionado' : 'certificados seleccionados' }}</p>
                                <p class="mt-1 truncate text-sm text-sky-700 dark:text-sky-300">{{ collect($pdfFiles)->map->getClientOriginalName()->join(', ') }}</p>
                            </div>
                            <button x-on:click="processCertificates" x-bind:disabled="processing" type="button" class="shrink-0 rounded-xl bg-sky-600 px-5 py-3 font-semibold text-white transition hover:bg-sky-700 disabled:cursor-wait disabled:opacity-60 dark:bg-sky-400 dark:text-slate-950 dark:hover:bg-sky-300">
                                <span x-show="! processing">Procesar certificados</span>
                                <span x-show="processing" x-cloak>Analizando certificados...</span>
                            </button>
                        </div>

                    </div>
                @endif

                <div x-show="processing" x-cloak class="border-t border-sky-200 bg-white/70 p-5 dark:border-sky-500/20 dark:bg-slate-900/40" aria-live="polite">
                    <div class="mb-2 flex items-center justify-between gap-4 text-sm font-semibold text-sky-800 dark:text-sky-200">
                        <span x-show="completed < total && progress < 90">Analizando certificado <span x-text="Math.min(completed + 1, total)"></span> de <span x-text="total"></span></span>
                        <span x-show="completed < total && progress >= 90">Finalizando extracción del certificado <span x-text="Math.min(completed + 1, total)"></span> de <span x-text="total"></span></span>
                        <span x-show="completed === total">Análisis completado</span>
                        <span x-text="`${Math.round(progress)}%`"></span>
                    </div>
                    <div role="progressbar" aria-label="Progreso del análisis de certificados" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="Math.round(progress)" class="h-3 w-full overflow-hidden rounded-full bg-sky-100 ring-1 ring-sky-200 dark:bg-slate-800 dark:ring-sky-500/20">
                        <div class="h-full animate-pulse rounded-full bg-gradient-to-r from-sky-500 via-cyan-300 to-sky-600 transition-[width] duration-500 ease-out dark:from-sky-400 dark:via-cyan-200 dark:to-sky-500" x-bind:style="`width: ${progress}%`"></div>
                    </div>
                </div>

                @error('pdfFiles')
                    <div class="border-t border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200">{{ $message }}</div>
                @enderror
                @error('pdfFiles.*')
                    <div class="border-t border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200">{{ $message }}</div>
                @enderror
            </section>

            @if ($certificates !== [])
                <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/[0.02]">
                    <h3 class="text-xl font-semibold">Certificados guardados</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Los PDF originales permanecen relacionados con esta gestión.</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($certificates as $certificate)
                            <div wire:key="stored-certificate-{{ $certificate['id'] }}" class="flex items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-slate-950/30">
                                <div class="min-w-0"><p class="truncate font-mono font-semibold">{{ $certificate['control_number'] }}</p><p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400">{{ $certificate['original_file_name'] }} · {{ $certificate['analyzed_at'] }}</p></div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button wire:click="downloadCertificate({{ $certificate['id'] }})" type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-white dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">Descargar PDF</button>
                                    @if ($this->canEdit() && $certificate['can_delete'])
                                        <button wire:click="openDeleteCertificateConfirmation({{ $certificate['id'] }})" type="button" class="rounded-lg border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 dark:border-rose-500/30 dark:text-rose-300 dark:hover:bg-rose-500/10">Quitar</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($certificates !== [])
                <section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.02]">
                    <div class="flex flex-col gap-4 border-b border-slate-200 p-6 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-600 dark:text-sky-300">Resultado de la comparación</p>
                            <h3 class="mt-2 text-2xl font-semibold">{{ count($certificates) }} {{ count($certificates) === 1 ? 'certificado procesado' : 'certificados procesados' }}</h3>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $expectedSerialCount }} seriales esperados · {{ $pdfOccurrenceCount }} apariciones válidas detectadas en los PDF.</p>
                        </div>
                        @if ($showPdfAnalysis)
                            <button wire:click="cancelPdfAnalysis" type="button" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">Cerrar análisis</button>
                        @else
                            <button wire:click="openPdfAnalysis" type="button" class="rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700 dark:bg-sky-400 dark:text-slate-950 dark:hover:bg-sky-300">Ver análisis</button>
                        @endif
                    </div>

                    <div class="grid gap-3 p-6 sm:grid-cols-2 lg:grid-cols-5">
                        <div class="rounded-2xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Certificados</p>
                            <p class="mt-1 text-2xl font-semibold text-emerald-950 dark:text-emerald-100">{{ count($matchedSerials) }}</p>
                            <p class="mt-2 text-xs leading-5 text-emerald-700 dark:text-emerald-300">Seriales registrados en la solicitud que fueron encontrados y asignados a uno de los certificados procesados.</p>
                            <button wire:click="exportCertificateAnalysis('certified')" wire:loading.attr="disabled" type="button" @disabled($matchedSerials === []) class="mt-3 rounded-lg border border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-400/30 dark:text-emerald-200 dark:hover:bg-emerald-500/10">Exportar</button>
                            @if (auth()->user()?->hasPermission(UserPermission::ImportCertificates) && ! $persistedDone)
                                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold text-emerald-900 dark:text-emerald-100"><input wire:model.live="includeCertified" type="checkbox" @disabled($matchedSerials === []) class="size-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500">Incluir al importar</label>
                            @endif
                        </div>
                        <div class="rounded-2xl bg-amber-50 p-4 dark:bg-amber-500/10">
                            <p class="text-sm font-semibold text-amber-700 dark:text-amber-300">Repetidos en PDF</p>
                            <p class="mt-1 text-2xl font-semibold text-amber-950 dark:text-amber-100">{{ count($duplicateSerials) }}</p>
                            <p class="mt-2 text-xs leading-5 text-amber-700 dark:text-amber-300">Seriales detectados más de una vez, dentro del mismo certificado o en certificados procesados anteriormente.</p>
                            <button wire:click="exportCertificateAnalysis('duplicates')" wire:loading.attr="disabled" type="button" @disabled($duplicateSerials === []) class="mt-3 rounded-lg border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-400/30 dark:text-amber-200 dark:hover:bg-amber-500/10">Exportar</button>
                            @if (auth()->user()?->hasPermission(UserPermission::ImportCertificates) && ! $persistedDone)
                                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold text-amber-900 dark:text-amber-100"><input wire:model.live="includeDuplicates" type="checkbox" @disabled($duplicateSerials === []) class="size-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500">Incluir al importar</label>
                            @endif
                        </div>
                        <div class="rounded-2xl bg-orange-50 p-4 dark:bg-orange-500/10">
                            <p class="text-sm font-semibold text-orange-700 dark:text-orange-300">No solicitados</p>
                            <p class="mt-1 text-2xl font-semibold text-orange-950 dark:text-orange-100">{{ count($unexpectedSerials) }}</p>
                            <p class="mt-2 text-xs leading-5 text-orange-700 dark:text-orange-300">Seriales encontrados en los certificados que no están registrados en la solicitud relacionada.</p>
                            <button wire:click="exportCertificateAnalysis('unexpected')" wire:loading.attr="disabled" type="button" @disabled($unexpectedSerials === []) class="mt-3 rounded-lg border border-orange-300 px-3 py-2 text-xs font-semibold text-orange-800 transition hover:bg-orange-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-orange-400/30 dark:text-orange-200 dark:hover:bg-orange-500/10">Exportar</button>
                            @if (auth()->user()?->hasPermission(UserPermission::ImportCertificates) && ! $persistedDone)
                                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold text-orange-900 dark:text-orange-100"><input wire:model.live="includeUnexpected" type="checkbox" @disabled($unexpectedSerials === []) class="size-4 rounded border-orange-300 text-orange-600 focus:ring-orange-500">Incluir al importar</label>
                            @endif
                        </div>
                        <div class="rounded-2xl bg-rose-50 p-4 dark:bg-rose-500/10">
                            <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">Sin certificar</p>
                            <p class="mt-1 text-2xl font-semibold text-rose-950 dark:text-rose-100">{{ count($missingSerials) }}</p>
                            <p class="mt-2 text-xs leading-5 text-rose-700 dark:text-rose-300">Seriales registrados en la solicitud que todavía no aparecen en ningún certificado procesado.</p>
                            <button wire:click="exportCertificateAnalysis('missing')" wire:loading.attr="disabled" type="button" @disabled($missingSerials === []) class="mt-3 rounded-lg border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-400/30 dark:text-rose-200 dark:hover:bg-rose-500/10">Exportar</button>
                            @if (auth()->user()?->hasPermission(UserPermission::ImportCertificates) && ! $persistedDone)
                                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold text-rose-900 dark:text-rose-100"><input wire:model.live="includeMissing" type="checkbox" @disabled($missingSerials === []) class="size-4 rounded border-rose-300 text-rose-600 focus:ring-rose-500">Incluir al importar</label>
                            @endif
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-4 dark:bg-white/10">
                            <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">Filas inválidas</p>
                            <p class="mt-1 text-2xl font-semibold">{{ count($invalidPdfRows) }}</p>
                            <p class="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">Filas de los certificados cuyos datos o posibles seriales no pudieron interpretarse como registros válidos.</p>
                            <button wire:click="exportCertificateAnalysis('invalid')" wire:loading.attr="disabled" type="button" @disabled($invalidPdfRows === []) class="mt-3 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-800 transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/20 dark:text-slate-200 dark:hover:bg-white/10">Exportar</button>
                            @if (auth()->user()?->hasPermission(UserPermission::ImportCertificates) && ! $persistedDone)
                                <label class="mt-4 flex cursor-pointer items-center gap-2 text-sm font-semibold"><input wire:model.live="includeInvalid" type="checkbox" @disabled($invalidPdfRows === []) class="size-4 rounded border-slate-300 text-slate-600 focus:ring-slate-500">Incluir al importar</label>
                            @endif
                        </div>
                    </div>

                    @if (session('managementCertificateImportResult'))
                        <div class="mx-6 mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('managementCertificateImportResult') }}</div>
                    @endif

                    @error('certificateSelection')
                        <div class="mx-6 mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200">{{ $message }}</div>
                    @enderror

                    @if (auth()->user()?->hasPermission(UserPermission::ImportCertificates) && ! $persistedDone)
                        <div class="border-t border-slate-200 px-6 py-5 dark:border-white/10">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-slate-600 dark:text-slate-300"><strong>{{ $this->selectedCertificateImportCount() }}</strong> registros seleccionados para importar al Maestro de Seriales Certificados.</p>
                                <button wire:click="importCertificateSelection" wire:loading.attr="disabled" wire:target="importCertificateSelection" type="button" @disabled($this->selectedCertificateImportCount() === 0) class="rounded-xl bg-violet-600 px-5 py-3 font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="importCertificateSelection">Importar seleccionados</span><span wire:loading wire:target="importCertificateSelection">Importando...</span></button>
                            </div>

                            <div wire:loading.flex wire:target="importCertificateSelection" class="mt-5 flex-col gap-2" aria-live="polite">
                                <div class="flex items-center justify-between gap-4 text-sm font-semibold text-violet-700 dark:text-violet-300">
                                    <span>Importando registros al maestro...</span>
                                    <span>Procesando</span>
                                </div>
                                <div role="progressbar" aria-label="Progreso de la importación al Maestro de Seriales Certificados" class="h-3 w-full overflow-hidden rounded-full bg-violet-100 ring-1 ring-violet-200 dark:bg-slate-800 dark:ring-violet-500/20">
                                    <div class="h-full w-full animate-pulse rounded-full bg-gradient-to-r from-violet-500 via-fuchsia-400 to-violet-600"></div>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Mantén esta ventana abierta hasta que finalice la importación.</p>
                            </div>
                        </div>
                    @endif

                    @if ($showPdfAnalysis)
                        @if ($exactPdfMatch)
                            <div class="border-t border-emerald-200 bg-emerald-50 px-6 py-5 font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">Todos los seriales de la solicitud están certificados correctamente. No se detectaron duplicados, faltantes, adicionales ni filas inválidas.</div>
                        @else
                            <div class="border-t border-amber-200 bg-amber-50 px-6 py-5 font-semibold text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">Los certificados presentan diferencias con la solicitud. Revisa las categorías detalladas a continuación.</div>
                        @endif

                    @if ($matchedSerials !== [])
                        <details class="border-t border-slate-200 p-6 dark:border-white/10">
                            <summary class="cursor-pointer font-semibold text-emerald-700 dark:text-emerald-300">Ver {{ count($matchedSerials) }} seriales certificados correctamente</summary>
                            <div class="mt-4 max-h-80 overflow-auto rounded-2xl border border-emerald-200 dark:border-emerald-500/20"><table class="w-full text-left text-sm"><thead class="sticky top-0 bg-emerald-50 text-xs uppercase text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200"><tr><th class="px-4 py-3">Serial</th><th class="px-4 py-3">Certificado</th></tr></thead><tbody class="divide-y divide-emerald-100 dark:divide-emerald-500/10">@foreach ($matchedSerials as $row)<tr wire:key="matched-certificate-{{ $row['certificate_id'] }}-{{ $row['serial'] }}"><td class="px-4 py-3 font-mono font-semibold">{{ $row['serial'] }}</td><td class="px-4 py-3 font-mono">{{ $row['certificate'] }}</td></tr>@endforeach</tbody></table></div>
                        </details>
                    @endif

                    <div class="border-t border-slate-200 dark:border-white/10">
                    @if ($duplicateSerials !== [])
                        <div class="bg-amber-50/60 p-6 dark:bg-amber-500/5">
                            <h4 class="font-semibold text-amber-950 dark:text-amber-100">Seriales repetidos en los certificados</h4>
                            <div class="mt-4 max-h-80 overflow-auto rounded-2xl border border-amber-200 bg-white dark:border-amber-500/20 dark:bg-slate-950/60"><table class="w-full min-w-3xl text-left text-sm"><thead class="sticky top-0 bg-amber-100 text-xs uppercase text-amber-700 dark:bg-amber-950 dark:text-amber-200"><tr><th class="px-4 py-3">Serial</th><th class="px-4 py-3">Certificado</th><th class="px-4 py-3">Apariciones repetidas</th><th class="px-4 py-3">Motivo</th></tr></thead><tbody class="divide-y divide-amber-100 dark:divide-amber-500/10">@foreach ($duplicateSerials as $row)<tr wire:key="duplicate-certificate-{{ $row['certificate_id'] }}-{{ $row['serial'] }}"><td class="px-4 py-3 font-mono font-semibold">{{ $row['serial'] }}</td><td class="px-4 py-3 font-mono">{{ $row['certificate'] }}</td><td class="px-4 py-3">{{ $row['occurrences'] }}</td><td class="px-4 py-3">{{ $row['reason'] }}</td></tr>@endforeach</tbody></table></div>
                        </div>
                    @endif

                    @if ($unexpectedSerials !== [])
                        <div class="border-t border-orange-200 bg-orange-50/60 p-6 dark:border-orange-500/20 dark:bg-orange-500/5">
                            <h4 class="font-semibold text-orange-950 dark:text-orange-100">Seriales certificados que no estaban en la solicitud</h4>
                            <div class="mt-4 max-h-80 w-full overflow-auto rounded-2xl border border-orange-200 bg-white dark:border-orange-500/20 dark:bg-slate-950"><table class="w-full min-w-3xl table-fixed border-separate border-spacing-0 text-left text-sm"><thead class="text-xs uppercase text-orange-700 dark:text-orange-200"><tr><th class="sticky top-0 z-10 w-5/12 bg-orange-100 px-4 py-3 dark:bg-orange-950">Serial</th><th class="sticky top-0 z-10 w-5/12 bg-orange-100 px-4 py-3 dark:bg-orange-950">Certificado</th><th class="sticky top-0 z-10 w-2/12 bg-orange-100 px-4 py-3 text-center dark:bg-orange-950">Apariciones</th></tr></thead><tbody>@foreach ($unexpectedSerials as $row)<tr wire:key="unexpected-certificate-{{ $row['certificate_id'] }}-{{ $row['serial'] }}" class="bg-white transition hover:bg-orange-50 dark:bg-slate-950 dark:hover:bg-orange-950/50"><td class="border-t border-orange-100 px-4 py-3 font-mono font-semibold dark:border-orange-500/10">{{ $row['serial'] }}</td><td class="border-t border-orange-100 px-4 py-3 font-mono dark:border-orange-500/10">{{ $row['certificate'] }}</td><td class="border-t border-orange-100 px-4 py-3 text-center font-semibold dark:border-orange-500/10">{{ $row['occurrences'] }}</td></tr>@endforeach</tbody></table></div>
                        </div>
                    @endif

                    @if ($missingSerials !== [])
                        <div class="border-t border-rose-200 bg-rose-50/60 p-6 dark:border-rose-500/20 dark:bg-rose-500/5">
                            <h4 class="font-semibold text-rose-950 dark:text-rose-100">Seriales de la solicitud que quedaron sin certificar</h4>
                            <div class="mt-4 max-h-80 overflow-auto rounded-2xl border border-rose-200 bg-white dark:border-rose-500/20 dark:bg-slate-950/60"><table class="w-full text-left text-sm"><thead class="sticky top-0 bg-rose-100 text-xs uppercase text-rose-700 dark:bg-rose-950 dark:text-rose-200"><tr><th class="px-4 py-3">Serial</th><th class="px-4 py-3">Certificado</th></tr></thead><tbody class="divide-y divide-rose-100 dark:divide-rose-500/10">@foreach ($missingSerials as $row)<tr wire:key="missing-certificate-{{ $row['serial'] }}"><td class="px-4 py-3 font-mono font-semibold">{{ $row['serial'] }}</td><td class="px-4 py-3 text-slate-500">Sin certificado</td></tr>@endforeach</tbody></table></div>
                        </div>
                    @endif

                    @if ($invalidPdfRows !== [])
                        <div class="border-t border-slate-200 bg-slate-50/80 p-6 dark:border-white/10 dark:bg-white/[0.02]">
                            <h4 class="font-semibold">Filas del PDF que no pudieron interpretarse</h4>
                            <div class="mt-4 max-h-80 overflow-auto rounded-2xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-950/60"><table class="w-full min-w-3xl text-left text-sm"><thead class="sticky top-0 bg-slate-100 text-xs uppercase text-slate-600 dark:bg-slate-950 dark:text-slate-300"><tr><th class="px-4 py-3">Certificado</th><th class="px-4 py-3">NIV detectado</th><th class="px-4 py-3">Motivo</th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-white/5">@foreach ($invalidPdfRows as $index => $row)<tr wire:key="invalid-certificate-{{ $row['certificate_id'] }}-{{ $index }}"><td class="px-4 py-3 font-mono">{{ $row['certificate'] }}</td><td class="px-4 py-3 font-mono">{{ $row['serial'] ?: 'No detectado' }}</td><td class="px-4 py-3">{{ $row['reason'] }}</td></tr>@endforeach</tbody></table></div>
                        </div>
                    @endif

                    
                    </div>
                    @endif
                </section>
            @endif

            @if ($persistedDone)
                <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">Esta gestión está hecha y no admite modificaciones.</p>
            @endif
        </div>
    </div>

    @if ($showDeleteCertificateConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-certificate-title">
            <button wire:click="closeDeleteCertificateConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar eliminación"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-6 shadow-2xl dark:border-rose-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300"><svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg></div>
                    <h3 id="delete-certificate-title" class="mt-5 text-2xl font-semibold">¿Quitar este certificado?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">El PDF y su análisis se eliminarán de esta gestión. Los resultados se recalcularán con los certificados restantes.</p>
                    <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button wire:click="closeDeleteCertificateConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">Cancelar</button>
                        <button wire:click="deleteCertificate" wire:loading.attr="disabled" wire:target="deleteCertificate" type="button" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"><span wire:loading.remove wire:target="deleteCertificate">Sí, quitar certificado</span><span wire:loading wire:target="deleteCertificate">Quitando...</span></button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
