<?php

use App\Actions\MotorcycleSerialRequests\ExtractSerialsFromWorkbook;
use App\Actions\MotorcycleSerialRequests\StoreProductSerialWorkbook;
use App\Actions\MotorcycleSerialRequests\StoreDuplicateSerialWorkbook;
use App\Models\MotorcycleSerialRequest;
use App\Models\MotorcycleSerialRequestLineSerial;
use App\Models\Product;
use App\VehicleIdentificationRecordManagementStatus;
use App\MotorcycleSerialRequestStatus;
use App\UserPermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $requestId = null;

    /** @var array<int, array{product_id: int|string|null, quantity: int|string, serials: string}> */
    public array $lines = [];

    /** @var array<int, array{name: string|null, path: string|null}> */
    #[Locked]
    public array $lineFiles = [];

    /** @var array<int, int|null> */
    #[Locked]
    public array $lineRecordIds = [];

    /** @var array<int, list<array{id: int, created_at: string|null, updated_at: string|null}>> */
    #[Locked]
    public array $lineSerialMetadata = [];

    public ?int $serialModalLineIndex = null;

    public string $serialSearch = '';

    public mixed $serialWorkbook = null;

    public ?string $importMessage = null;

    #[Locked]
    public bool $persistedDone = false;

    #[Locked]
    public ?int $managementRecordId = null;

    public string $status = MotorcycleSerialRequestStatus::Draft->value;

    public string $requestDate = '';

    public bool $showDeleteConfirmation = false;

    public bool $showFinalizeConfirmation = false;

    public bool $showSerialConflictModal = false;

    #[Locked]
    public string $serialConflictMessage = '';

    #[Locked]
    public ?string $serialConflictFileName = null;

    #[Locked]
    public ?string $serialConflictFilePath = null;

    public function mount(?int $requestId = null): void
    {
        $this->requestId = $requestId;

        if ($requestId === null) {
            $this->authorizePermission(UserPermission::CreateMotorcycleSerialRequests);
            $this->requestDate = now()->toDateString();
            $this->addLine();

            return;
        }

        $this->authorizePermission(UserPermission::ViewMotorcycleSerialRequests);
        $serialRequest = MotorcycleSerialRequest::query()
            ->with(['lines.serialEntries', 'vehicleIdentificationRecordManagement:id,motorcycle_serial_request_id'])
            ->findOrFail($requestId);
        $this->status = $serialRequest->status->value;
        $this->requestDate = $serialRequest->request_date?->toDateString() ?? '';
        $this->persistedDone = $serialRequest->status === MotorcycleSerialRequestStatus::Done;
        $this->managementRecordId = $serialRequest->vehicleIdentificationRecordManagement?->id;
        $this->lines = $serialRequest->lines->map(fn ($line): array => [
            'product_id' => $line->product_id,
            'quantity' => $line->quantity,
            'serials' => $line->serialEntries->pluck('serial')->implode("\n"),
        ])->all();
        $this->lineFiles = $serialRequest->lines->map(fn ($line): array => [
            'name' => $line->source_file_name,
            'path' => $line->source_file_path,
        ])->all();
        $this->lineRecordIds = $serialRequest->lines->pluck('id')->all();
        $this->lineSerialMetadata = $serialRequest->lines->map(fn ($line): array => $line->serialEntries
            ->map(fn ($serial): array => [
                'id' => $serial->id,
                'created_at' => $serial->created_at?->format('d/m/Y H:i'),
                'updated_at' => $serial->updated_at?->format('d/m/Y H:i'),
            ])->all())->all();
    }

    /** @return Collection<int, Product> */
    public function products(): Collection
    {
        return Product::query()->orderBy('name')->get(['id', 'name', 'niv']);
    }

    /** @return array<int, MotorcycleSerialRequestStatus> */
    public function statuses(): array
    {
        return MotorcycleSerialRequestStatus::cases();
    }

    public function isReadOnly(): bool
    {
        if ($this->persistedDone) {
            return true;
        }

        if ($this->requestId === null) {
            return auth()->user()?->hasPermission(UserPermission::CreateMotorcycleSerialRequests) !== true;
        }

        return auth()->user()?->hasPermission(UserPermission::EditMotorcycleSerialRequests) !== true;
    }

    public function areLinesReadOnly(): bool
    {
        return $this->isReadOnly() || $this->status === MotorcycleSerialRequestStatus::Done->value;
    }

    public function addLine(): void
    {
        abort_if($this->areLinesReadOnly(), 403);

        $this->lines[] = ['product_id' => null, 'quantity' => 1, 'serials' => ''];
        $this->lineFiles[] = ['name' => null, 'path' => null];
        $this->lineRecordIds[] = null;
        $this->lineSerialMetadata[] = [];
    }

    public function removeLine(int $index): void
    {
        abort_if($this->areLinesReadOnly(), 403);
        abort_if(! array_key_exists($index, $this->lines), 404);

        unset($this->lines[$index]);
        unset($this->lineFiles[$index]);
        unset($this->lineRecordIds[$index], $this->lineSerialMetadata[$index]);
        $this->lines = array_values($this->lines);
        $this->lineFiles = array_values($this->lineFiles);
        $this->lineRecordIds = array_values($this->lineRecordIds);
        $this->lineSerialMetadata = array_values($this->lineSerialMetadata);
        $this->serialModalLineIndex = null;
    }

    public function openSerialModal(int $index): void
    {
        abort_if(! array_key_exists($index, $this->lines), 404);

        $this->serialModalLineIndex = $index;
        $this->serialSearch = '';
    }

    public function closeSerialModal(): void
    {
        $this->serialModalLineIndex = null;
        $this->serialSearch = '';
    }

    public function closeSerialConflictModal(): void
    {
        if ($this->serialConflictFilePath !== null) {
            Storage::disk('local')->delete($this->serialConflictFilePath);
        }

        $this->showSerialConflictModal = false;
        $this->serialConflictMessage = '';
        $this->serialConflictFileName = null;
        $this->serialConflictFilePath = null;
    }

    public function downloadSerialConflicts(): mixed
    {
        abort_if(
            $this->serialConflictFilePath === null
            || ! Storage::disk('local')->exists($this->serialConflictFilePath),
            404,
        );

        return Storage::disk('local')->download(
            $this->serialConflictFilePath,
            $this->serialConflictFileName ?? 'Seriales repetidos.xlsx',
        );
    }

    public function lineSerialCount(int $index): int
    {
        if (! isset($this->lines[$index])) {
            return 0;
        }

        return count(array_filter(array_map(
            'trim',
            preg_split('/\R/u', $this->lines[$index]['serials']) ?: [],
        )));
    }

    /** @return list<array{id: int|null, line_id: int|null, serial: string, created_at: string|null, updated_at: string|null}> */
    public function modalSerials(): array
    {
        if ($this->serialModalLineIndex === null || ! isset($this->lines[$this->serialModalLineIndex])) {
            return [];
        }

        $index = $this->serialModalLineIndex;
        $serials = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/u', $this->lines[$index]['serials']) ?: [],
        )));

        return collect($serials)->map(function (string $serial, int $serialIndex) use ($index): array {
            $metadata = $this->lineSerialMetadata[$index][$serialIndex] ?? null;

            return [
                'id' => $metadata['id'] ?? null,
                'line_id' => $this->lineRecordIds[$index] ?? null,
                'serial' => $serial,
                'created_at' => $metadata['created_at'] ?? null,
                'updated_at' => $metadata['updated_at'] ?? null,
            ];
        })->when(
            trim($this->serialSearch) !== '',
            fn ($serialRows) => $serialRows->filter(
                fn (array $serialRow): bool => str_contains(
                    Str::upper($serialRow['serial']),
                    Str::upper(trim($this->serialSearch)),
                ),
            ),
        )->values()->all();
    }

    public function importSerialWorkbook(
        ExtractSerialsFromWorkbook $extractor,
        StoreProductSerialWorkbook $workbookStorage,
    ): void
    {
        abort_if($this->areLinesReadOnly(), 403);

        $this->resetErrorBag('serialWorkbook');
        $this->importMessage = null;
        $this->validate([
            'serialWorkbook' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ], [
            'serialWorkbook.required' => 'Selecciona un archivo Excel.',
            'serialWorkbook.mimes' => 'El archivo debe estar en formato .xlsx.',
            'serialWorkbook.max' => 'El archivo no puede superar 10 MB.',
        ]);

        try {
            $serials = $extractor->handle($this->serialWorkbook->getRealPath());
            $serialsByNiv = collect($serials)->groupBy(fn (string $serial): string => substr($serial, 3, 2));
            $productsByNiv = Product::query()
                ->whereNotNull('niv')
                ->get(['id', 'name', 'niv'])
                ->groupBy(fn (Product $product): string => Str::upper(trim((string) $product->niv)));
            $ignoredSerialCount = $serialsByNiv
                ->reject(fn ($productSerials, string $niv): bool => $productsByNiv->has($niv))
                ->sum(fn ($productSerials): int => $productSerials->count());
            $serialsByNiv = $serialsByNiv
                ->filter(fn ($productSerials, string $niv): bool => $productsByNiv->has($niv));

            if ($serialsByNiv->isEmpty()) {
                throw new \RuntimeException('Se encontraron seriales, pero ninguno corresponde al NIV de un producto registrado.');
            }

            foreach ($serialsByNiv->keys() as $niv) {
                $matchingProducts = $productsByNiv->get($niv, collect());

                if ($matchingProducts->count() > 1) {
                    throw new \RuntimeException("El NIV {$niv} está asignado a más de un producto. Corrige el maestro de productos antes de importar.");
                }

                if (collect($this->lines)->contains(fn (array $line): bool => (int) ($line['product_id'] ?? 0) === $matchingProducts->first()->id)) {
                    throw new \RuntimeException("Ya existe una línea para el producto {$matchingProducts->first()->name}. Elimínala antes de cargar este archivo.");
                }
            }

            if (count($this->lines) === 1 && empty($this->lines[0]['product_id']) && trim($this->lines[0]['serials']) === '') {
                $this->lines = [];
                $this->lineFiles = [];
                $this->lineRecordIds = [];
                $this->lineSerialMetadata = [];
            }

            $originalName = $this->serialWorkbook->getClientOriginalName();
            $singleProductFile = $serialsByNiv->count() === 1
                ? [
                    'name' => $originalName,
                    'path' => $this->serialWorkbook->store('motorcycle-serial-request-workbooks', 'local'),
                ]
                : null;

            foreach ($serialsByNiv as $niv => $productSerials) {
                $product = $productsByNiv->get($niv)->first();
                $lineFile = $singleProductFile ?? $workbookStorage->handle(
                    $product->name,
                    $niv,
                    $productSerials->values()->all(),
                );
                $this->lines[] = [
                    'product_id' => $product->id,
                    'quantity' => $productSerials->count(),
                    'serials' => $productSerials->implode("\n"),
                ];
                $this->lineFiles[] = $lineFile;
                $this->lineRecordIds[] = null;
                $this->lineSerialMetadata[] = [];
            }

            $this->serialWorkbook = null;
            $importedSerialCount = $serialsByNiv->sum(fn ($productSerials): int => $productSerials->count());
            $this->importMessage = $importedSerialCount.' seriales cargados correctamente en '.$serialsByNiv->count().' línea(s) de producto.';

            if ($ignoredSerialCount > 0) {
                $this->importMessage .= ' Se ignoraron '.$ignoredSerialCount.' seriales cuyo NIV no pertenece a un producto registrado.';
            }
        } catch (\RuntimeException $exception) {
            $this->addError('serialWorkbook', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('serialWorkbook', 'No fue posible procesar el archivo Excel. Verifica que no esté dañado e inténtalo nuevamente.');
        }
    }

    public function downloadLineFile(int $index): mixed
    {
        $file = $this->lineFiles[$index] ?? null;
        abort_if($file === null || empty($file['path']) || ! Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'] ?: basename($file['path']));
    }

    public function canDeleteRequest(): bool
    {
        if ($this->requestId === null) {
            return false;
        }

        $permission = $this->persistedDone
            ? UserPermission::DeleteCompletedMotorcycleSerialRequests
            : UserPermission::DeleteMotorcycleSerialRequests;

        return auth()->user()?->hasPermission($permission) === true;
    }

    public function canFinalizeRequest(): bool
    {
        return $this->requestId !== null
            && ! $this->persistedDone
            && auth()->user()?->hasPermission(UserPermission::EditMotorcycleSerialRequests) === true;
    }

    public function openFinalizeConfirmation(): void
    {
        abort_unless($this->canFinalizeRequest(), 403);

        $this->showFinalizeConfirmation = true;
    }

    public function closeFinalizeConfirmation(): void
    {
        $this->showFinalizeConfirmation = false;
    }

    public function openDeleteConfirmation(): void
    {
        abort_unless($this->canDeleteRequest(), 403);

        $this->showDeleteConfirmation = true;
    }

    public function closeDeleteConfirmation(): void
    {
        $this->showDeleteConfirmation = false;
    }

    public function deleteRequest(): mixed
    {
        abort_if($this->requestId === null, 404);

        $serialRequest = MotorcycleSerialRequest::query()->findOrFail($this->requestId);
        $this->authorizePermission(
            $serialRequest->status === MotorcycleSerialRequestStatus::Done
                ? UserPermission::DeleteCompletedMotorcycleSerialRequests
                : UserPermission::DeleteMotorcycleSerialRequests,
        );

        $serialRequest->delete();

        return redirect()
            ->route('motorcycle_serial_requests.index')
            ->with('status', 'Solicitud eliminada correctamente.');
    }

    public function setStatus(string $status): void
    {
        abort_if($this->isReadOnly(), 403);

        $requestStatus = MotorcycleSerialRequestStatus::tryFrom($status);
        abort_if(
            $requestStatus === null || $requestStatus === MotorcycleSerialRequestStatus::Done,
            422,
        );

        $this->status = $requestStatus->value;
    }

    public function save(): mixed
    {
        abort_if($this->status === MotorcycleSerialRequestStatus::Done->value, 422);

        return $this->persist(false);
    }

    public function finalize(): mixed
    {
        abort_if($this->requestId === null, 404);
        abort_if($this->persistedDone, 403);
        $this->authorizePermission(UserPermission::EditMotorcycleSerialRequests);

        $this->status = MotorcycleSerialRequestStatus::Done->value;

        return $this->persist(true);
    }

    private function persist(bool $finalizing): mixed
    {
        abort_if($this->persistedDone, 403);
        $this->authorizePermission(
            $this->requestId === null
                ? UserPermission::CreateMotorcycleSerialRequests
                : UserPermission::EditMotorcycleSerialRequests,
        );
        if ($this->serialConflictFilePath !== null) {
            Storage::disk('local')->delete($this->serialConflictFilePath);
        }

        $this->showSerialConflictModal = false;
        $this->serialConflictMessage = '';
        $this->serialConflictFileName = null;
        $this->serialConflictFilePath = null;

        if ($finalizing) {
            $this->status = MotorcycleSerialRequestStatus::Done->value;
        }

        $validated = $this->validate([
            'requestDate' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'distinct', Rule::exists((new Product)->getTable(), 'id')],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.serials' => ['required', 'string'],
            'status' => ['required', Rule::enum(MotorcycleSerialRequestStatus::class)],
        ], [
            'requestDate.date' => 'La fecha seleccionada no es válida.',
            'lines.required' => 'Agrega al menos una línea.',
            'lines.min' => 'Agrega al menos una línea.',
            'lines.*.product_id.required' => 'Selecciona un producto.',
            'lines.*.product_id.exists' => 'El producto seleccionado no existe.',
            'lines.*.product_id.distinct' => 'No puedes repetir el mismo producto.',
            'lines.*.quantity.required' => 'Ingresa la cantidad.',
            'lines.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'lines.*.quantity.min' => 'La cantidad debe ser al menos 1.',
            'lines.*.serials.required' => 'Ingresa los seriales.',
            'status.required' => 'Selecciona un estado.',
        ]);

        $serialOccurrences = collect($validated['lines'])->flatMap(function (array $line, int $lineIndex): array {
            return collect(preg_split('/\R/u', $line['serials']) ?: [])
                ->map(fn (string $serial): string => Str::upper(trim($serial)))
                ->filter()
                ->map(fn (string $serial): array => ['serial' => $serial, 'line_index' => $lineIndex])
                ->values()
                ->all();
        });
        $repeatedInRequest = $serialOccurrences
            ->groupBy('serial')
            ->filter(fn ($occurrences): bool => $occurrences->count() > 1)
            ->keys();
        $existingSerials = collect();

        $serialOccurrences->pluck('serial')->unique()->chunk(500)->each(function ($serialChunk) use ($existingSerials): void {
            MotorcycleSerialRequestLineSerial::query()
                ->whereIn('serial', $serialChunk)
                ->when($this->requestId !== null, fn ($query) => $query->whereHas(
                    'line',
                    fn ($lineQuery) => $lineQuery->where('motorcycle_serial_request_id', '!=', $this->requestId),
                ))
                ->with('line:id,motorcycle_serial_request_id')
                ->get()
                ->each(fn (MotorcycleSerialRequestLineSerial $serial) => $existingSerials->put(
                    $serial->serial,
                    $serial->line->motorcycle_serial_request_id,
                ));
        });

        if ($repeatedInRequest->isNotEmpty() || $existingSerials->isNotEmpty()) {
            $conflictingSerials = $repeatedInRequest
                ->merge($existingSerials->keys())
                ->unique()
                ->sort()
                ->values();
            $conflictFile = app(StoreDuplicateSerialWorkbook::class)->handle(
                $conflictingSerials->all(),
                $this->requestId,
            );

            if ($finalizing && $this->requestId !== null) {
                $this->status = MotorcycleSerialRequest::query()->findOrFail($this->requestId)->status->value;
            }

            $this->serialConflictMessage = 'No se puede guardar porque existen seriales repetidos.';
            $this->serialConflictFileName = $conflictFile['name'];
            $this->serialConflictFilePath = $conflictFile['path'];
            $this->showSerialConflictModal = true;

            return null;
        }

        [$serialRequest, $message] = DB::transaction(function () use ($validated, $finalizing): array {
            if ($this->requestId === null) {
                $serialRequest = MotorcycleSerialRequest::create([
                    'user_id' => auth()->id(),
                    'request_date' => $validated['requestDate'],
                    'status' => $validated['status'],
                ]);
                $message = 'Solicitud creada correctamente.';
            } else {
                $serialRequest = MotorcycleSerialRequest::query()->findOrFail($this->requestId);
                $serialRequest->update([
                    'request_date' => $validated['requestDate'],
                    'status' => $validated['status'],
                ]);
                $serialRequest->lines()->delete();
                $message = $finalizing
                    ? 'Solicitud finalizada correctamente.'
                    : 'Solicitud actualizada correctamente.';
            }

            $products = Product::query()
                ->whereIn('id', collect($validated['lines'])->pluck('product_id'))
                ->get(['id', 'name', 'niv'])
                ->keyBy('id');
            $workbookStorage = app(StoreProductSerialWorkbook::class);
            $lines = collect($validated['lines'])->map(function (array $line, int $index) use ($products, $serialRequest, $workbookStorage): array {
                $product = $products->get($line['product_id']);
                $serials = array_values(array_unique(array_filter(array_map(
                    fn (string $serial): string => Str::upper(trim($serial)),
                    preg_split('/\R/u', $line['serials']) ?: [],
                ))));
                unset($line['serials']);

                return [
                    'attributes' => [
                        ...$line,
                        'source_file_name' => empty($this->lineFiles[$index]['path'])
                            ? null
                            : $workbookStorage->fileName(
                                $serialRequest->id,
                                $product->name,
                                Str::upper(trim((string) $product->niv)),
                            ),
                        'source_file_path' => $this->lineFiles[$index]['path'] ?? null,
                    ],
                    'serials' => $serials,
                ];
            })->all();

            foreach ($lines as $lineData) {
                $requestLine = $serialRequest->lines()->create($lineData['attributes']);
                $requestLine->serialEntries()->createMany(
                    collect($lineData['serials'])
                        ->map(fn (string $serial): array => ['serial' => $serial])
                        ->all(),
                );
            }

            if ($finalizing) {
                $serialRequest->vehicleIdentificationRecordManagement()->firstOrCreate([], [
                    'status' => VehicleIdentificationRecordManagementStatus::Draft,
                ]);
            }

            return [$serialRequest, $message];
        });

        return redirect()
            ->route('motorcycle_serial_requests.edit', $serialRequest)
            ->with('status', $message);
    }

    private function authorizePermission(UserPermission $permission): void
    {
        abort_unless(auth()->user()?->hasPermission($permission), 403);
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('motorcycle_serial_requests.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                Volver a la lista
            </a>
            <span class="text-sm text-slate-500 dark:text-slate-400">{{ $requestId ? "Solicitud #{$requestId}" : 'Nueva solicitud' }}</span>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($this->canDeleteRequest())
                <button wire:click="openDeleteConfirmation" type="button" class="rounded-xl border border-rose-200 bg-white px-5 py-3 font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:bg-transparent dark:text-rose-300 dark:hover:bg-rose-500/10">Eliminar</button>
            @endif

            @if (! $this->isReadOnly())
                @if ($this->canFinalizeRequest())
                    <button wire:click="openFinalizeConfirmation" type="button" class="rounded-xl bg-emerald-600 px-6 py-3 font-semibold text-white shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-500">
                        Finalizar
                    </button>
                @endif

                <button wire:click="save" wire:loading.attr="disabled" wire:target="save" type="button" class="rounded-xl bg-violet-600 px-6 py-3 font-semibold text-white shadow-lg shadow-violet-500/25 transition hover:bg-violet-500 disabled:cursor-wait disabled:opacity-60">
                    <span wire:loading.remove wire:target="save">Guardar</span>
                    <span wire:loading wire:target="save">Guardando...</span>
                </button>
            @elseif (! $this->canDeleteRequest())
                <span class="rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">Solo lectura</span>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</div>
    @endif

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="border-b border-slate-200 bg-slate-50/70 px-6 py-6 dark:border-white/10 dark:bg-slate-950/30 sm:px-8">
            <p class="mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Estado de la solicitud</p>
            <div class="grid grid-cols-3 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-900">
                @foreach ($this->statuses() as $availableStatus)
                    <button
                        wire:key="request-status-{{ $availableStatus->value }}"
                        @if ($availableStatus !== MotorcycleSerialRequestStatus::Done)
                            wire:click="setStatus('{{ $availableStatus->value }}')"
                        @else
                            title="El estado Hecho solo se asigna mediante la acción de cierre"
                        @endif
                        type="button"
                        @disabled($this->isReadOnly() || $availableStatus === MotorcycleSerialRequestStatus::Done)
                        @class([
                            'relative px-3 py-3 text-sm font-semibold transition sm:px-5',
                            'border-l border-slate-200 dark:border-white/10' => ! $loop->first,
                            'bg-slate-700 text-white dark:bg-slate-600' => $status === $availableStatus->value && $availableStatus === MotorcycleSerialRequestStatus::Draft,
                            'bg-amber-500 text-white' => $status === $availableStatus->value && $availableStatus === MotorcycleSerialRequestStatus::InProgress,
                            'bg-emerald-600 text-white' => $status === $availableStatus->value && $availableStatus === MotorcycleSerialRequestStatus::Done,
                            'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-white/5' => $status !== $availableStatus->value && ! $this->isReadOnly() && $availableStatus !== MotorcycleSerialRequestStatus::Done,
                            'cursor-not-allowed text-slate-400' => $status !== $availableStatus->value && ($this->isReadOnly() || $availableStatus === MotorcycleSerialRequestStatus::Done),
                        ])
                    >
                        {{ $availableStatus->label() }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="p-6 sm:p-8">
            <div class="mb-8 grid max-w-4xl gap-5 md:grid-cols-2">
                <div>
                    <label for="request-date" class="mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-200">Fecha</label>
                    <input id="request-date" wire:model="requestDate" type="date" @disabled($this->isReadOnly()) class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-950 outline-none transition focus:border-violet-500 focus:ring-4 focus:ring-violet-500/10 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-white/10 dark:bg-slate-950/40 dark:text-white dark:focus:border-violet-400 dark:disabled:bg-white/5">
                    @error('requestDate') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                </div>
                @if ($managementRecordId !== null)
                    <div>
                        <p class="mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-200">Gestión de constancia relacionada</p>
                        @if (auth()->user()?->hasPermission(UserPermission::ViewVehicleIdentificationRecordManagement))
                            <a href="{{ route('vehicle_identification_record_management.edit', $managementRecordId) }}" class="flex w-full items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 font-semibold text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200 dark:hover:bg-emerald-500/20">
                                Gestión NIV #{{ $managementRecordId }}
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
                            </a>
                        @else
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 font-semibold dark:border-white/10 dark:bg-white/5">Gestión NIV #{{ $managementRecordId }}</div>
                        @endif
                    </div>
                @endif
            </div>

            <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-xl font-semibold">Productos y seriales</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Líneas relacionadas con esta solicitud.</p>
                </div>

                @unless ($this->areLinesReadOnly())
                    <label class="inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-xl bg-violet-600 px-5 py-3 font-semibold text-white shadow-sm transition hover:bg-violet-500">
                        <input id="serial-workbook" wire:model="serialWorkbook" type="file" accept=".xlsx" class="sr-only">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0-4 4m4-4 4 4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/>
                        </svg>
                        Seleccionar Excel
                    </label>
                @endunless
            </div>

            @unless ($this->areLinesReadOnly())
                <div wire:loading wire:target="serialWorkbook" class="mb-6 w-full rounded-2xl border border-violet-200 bg-violet-50 px-5 py-4 text-sm font-semibold text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-200">
                    <span class="inline-flex items-center gap-2">
                        <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"/></svg>
                        Cargando archivo...
                    </span>
                </div>

                @if ($serialWorkbook)
                    <div wire:loading.remove wire:target="serialWorkbook" class="mb-6 flex w-full flex-col gap-4 rounded-2xl border border-violet-200 bg-violet-50 p-5 dark:border-violet-500/20 dark:bg-violet-500/10 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-violet-950 dark:text-violet-100">{{ $serialWorkbook->getClientOriginalName() }}</p>
                            <p class="mt-1 text-sm text-violet-700 dark:text-violet-300">Se analizarán todas las hojas. Las posiciones 4 y 5 de cada serial deben coincidir con el NIV de un producto registrado.</p>
                        </div>
                        <button wire:click="importSerialWorkbook" wire:loading.attr="disabled" wire:target="importSerialWorkbook" type="button" class="shrink-0 rounded-xl bg-violet-600 px-5 py-3 font-semibold text-white transition hover:bg-violet-500 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="importSerialWorkbook">Procesar Excel</span>
                            <span wire:loading wire:target="importSerialWorkbook">Procesando...</span>
                        </button>
                    </div>
                @endif

                @error('serialWorkbook') <p class="mb-6 rounded-xl bg-rose-100 px-4 py-3 text-sm font-medium text-rose-700 dark:bg-rose-500/15 dark:text-rose-200">{{ $message }}</p> @enderror
                @if ($importMessage)
                    <p role="status" class="mb-6 rounded-xl bg-emerald-100 px-4 py-3 text-sm font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">{{ $importMessage }}</p>
                @endif
            @endunless

            <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-white/10">
                <table class="w-full min-w-5xl table-fixed text-left">
                    <thead class="bg-slate-100/80 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/60 dark:text-slate-400">
                        <tr>
                            <th class="w-[28%] px-4 py-3 font-semibold">Producto</th>
                            <th class="w-32 px-4 py-3 font-semibold">Cantidad</th>
                            <th class="px-4 py-3 font-semibold">Seriales</th>
                            <th class="w-56 px-4 py-3 font-semibold">Archivo Excel</th>
                            <th class="w-14 px-2 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-white/10 dark:bg-white/[0.02]">
                        @foreach ($lines as $index => $line)
                            <tr wire:key="request-line-{{ $index }}" class="align-top transition hover:bg-violet-50/40 dark:hover:bg-violet-500/5">
                                <td class="p-2">
                                    <select aria-label="Producto de la línea {{ $index + 1 }}" wire:model="lines.{{ $index }}.product_id" @disabled($this->areLinesReadOnly()) class="w-full border-0 bg-transparent px-3 py-2.5 outline-none focus:ring-2 focus:ring-violet-500/20 disabled:cursor-not-allowed dark:text-white">
                                        <option value="">Buscar o seleccionar producto...</option>
                                        @foreach ($this->products() as $product)
                                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$index}.product_id") <p class="px-3 pb-2 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                                </td>
                                <td class="p-2">
                                    <input aria-label="Cantidad de la línea {{ $index + 1 }}" wire:model="lines.{{ $index }}.quantity" type="number" min="1" step="1" @disabled($this->areLinesReadOnly()) class="w-full border-0 bg-transparent px-3 py-2.5 outline-none focus:ring-2 focus:ring-violet-500/20 disabled:cursor-not-allowed dark:text-white">
                                    @error("lines.{$index}.quantity") <p class="px-3 pb-2 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                                </td>
                                <td class="p-2">
                                    <button wire:click="openSerialModal({{ $index }})" type="button" class="inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-200 dark:hover:bg-violet-500/20">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                                        Ver {{ $this->lineSerialCount($index) }} seriales
                                    </button>
                                    @error("lines.{$index}.serials") <p class="px-3 pb-2 text-xs text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                                </td>
                                <td class="p-3">
                                    @if (! empty($lineFiles[$index]['path']))
                                        <button wire:click="downloadLineFile({{ $index }})" type="button" class="flex max-w-full items-center gap-2 text-left text-sm font-semibold text-violet-600 hover:text-violet-500 dark:text-violet-300">
                                            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/></svg>
                                            <span class="truncate" title="{{ $lineFiles[$index]['name'] }}">{{ $lineFiles[$index]['name'] }}</span>
                                        </button>
                                    @else
                                        <span class="text-xs text-slate-400">Sin archivo</span>
                                    @endif
                                </td>
                                <td class="p-2 text-center">
                                    @if (! $this->areLinesReadOnly())
                                        <button wire:click="removeLine({{ $index }})" type="button" class="grid size-9 place-items-center rounded-lg text-slate-400 transition hover:bg-rose-100 hover:text-rose-600 dark:hover:bg-rose-500/10 dark:hover:text-rose-300" aria-label="Quitar línea {{ $index + 1 }}">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7.5h12m-10.5 0 .75 12h7.5l.75-12M9.75 7.5V4.5h4.5v3"/></svg>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        @unless ($this->areLinesReadOnly())
                            <tr>
                                <td colspan="5" class="px-4 py-3">
                                    <button wire:click="addLine" type="button" class="text-sm font-semibold text-violet-600 transition hover:text-violet-500 dark:text-violet-300">Agregar una línea</button>
                                </td>
                            </tr>
                        @endunless
                    </tbody>
                </table>
            </div>

            @error('lines') <p class="mt-3 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror

            @if ($this->products()->isEmpty())
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">No existen productos registrados. Crea primero un producto desde Configuración → Productos.</p>
            @endif

            @if ($persistedDone)
                <p class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">Esta solicitud está hecha y no admite modificaciones.</p>
            @endif
        </div>
    </div>

    @if ($showSerialConflictModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="serial-conflict-title">
            <button wire:click="closeSerialConflictModal" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cerrar alerta de seriales repetidos"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-3xl border border-amber-200 bg-white shadow-2xl dark:border-amber-500/30 dark:bg-slate-900">
                    <div class="overflow-y-auto p-6 pb-4 sm:p-8 sm:pb-4">
                    <div class="grid size-14 place-items-center rounded-2xl bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.5 2.7 18a2 2 0 0 0 1.74 3h15.12a2 2 0 0 0 1.74-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/></svg>
                    </div>
                    <h3 id="serial-conflict-title" class="mt-5 text-2xl font-semibold">Seriales repetidos</h3>
                    <div class="mt-3 rounded-2xl bg-amber-50 p-4 text-sm leading-7 text-amber-950 dark:bg-amber-500/10 dark:text-amber-100">
                        {{ $serialConflictMessage }}
                    </div>
                    <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">La solicitud no fue guardada. Revisa los seriales indicados antes de intentarlo nuevamente.</p>
                    </div>
                    <div class="shrink-0 border-t border-slate-200 bg-white px-6 py-5 dark:border-white/10 dark:bg-slate-900 sm:px-8">
                        <div class="flex flex-col items-center justify-center gap-3 sm:flex-row">
                            @if ($serialConflictFilePath)
                                <button wire:click="downloadSerialConflicts" wire:loading.attr="disabled" wire:target="downloadSerialConflicts" type="button" class="inline-flex w-full items-center justify-center gap-2 rounded-xl border-2 border-slate-950 bg-white px-5 py-3 font-bold text-slate-950 transition hover:bg-slate-950 hover:text-white disabled:opacity-60 dark:border-white dark:bg-slate-900 dark:text-white dark:hover:bg-white dark:hover:text-slate-950 sm:w-auto">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/></svg>
                                    Descargar seriales repetidos
                                </button>
                            @endif
                            <button wire:click="closeSerialConflictModal" type="button" class="w-full rounded-xl bg-violet-600 px-8 py-3 font-semibold text-white shadow-lg shadow-violet-500/25 transition hover:bg-violet-500 focus:outline-none focus:ring-4 focus:ring-violet-500/30 sm:w-56">OK</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($serialModalLineIndex !== null)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="serial-list-title">
            <button wire:click="closeSerialModal" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cerrar seriales"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-6xl overflow-hidden rounded-3xl border border-violet-200 bg-white shadow-2xl dark:border-violet-500/30 dark:bg-slate-900">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5 dark:border-white/10 sm:px-8">
                        <div>
                            <h3 id="serial-list-title" class="text-2xl font-semibold">Seriales del producto</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Línea {{ ($serialModalLineIndex ?? 0) + 1 }} · {{ count($this->modalSerials()) }} registros</p>
                        </div>
                        <button wire:click="closeSerialModal" type="button" class="grid size-10 shrink-0 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Cerrar ventana">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18"/></svg>
                        </button>
                    </div>

                    <div class="border-b border-slate-200 bg-slate-50/70 px-6 py-4 dark:border-white/10 dark:bg-slate-950/30 sm:px-8">
                        <label for="serial-search" class="sr-only">Buscar serial</label>
                        <div class="relative max-w-xl">
                            <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-4-4"/></svg>
                            <input id="serial-search" wire:model.live.debounce.250ms="serialSearch" type="search" autocomplete="off" placeholder="Buscar un serial..." class="w-full rounded-xl border border-slate-300 bg-white py-3 pl-12 pr-4 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:ring-4 focus:ring-violet-500/10 dark:border-white/10 dark:bg-slate-900 dark:text-white dark:focus:border-violet-400">
                        </div>
                    </div>

                    <div class="max-h-[65vh] overflow-auto">
                        <table class="w-full min-w-4xl text-left text-sm">
                            <thead class="sticky top-0 bg-slate-100 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">ID</th>
                                    <th class="px-5 py-3 font-semibold">ID línea</th>
                                    <th class="px-5 py-3 font-semibold">Serial</th>
                                    <th class="px-5 py-3 font-semibold">Creado</th>
                                    <th class="px-5 py-3 font-semibold">Actualizado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                @forelse ($this->modalSerials() as $serialRow)
                                    <tr wire:key="modal-serial-{{ $loop->index }}-{{ $serialRow['serial'] }}" class="hover:bg-violet-50/50 dark:hover:bg-violet-500/5">
                                        <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $serialRow['id'] ?? 'Pendiente' }}</td>
                                        <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $serialRow['line_id'] ?? 'Pendiente' }}</td>
                                        <td class="px-5 py-3 font-mono font-semibold text-slate-950 dark:text-white">{{ $serialRow['serial'] }}</td>
                                        <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $serialRow['created_at'] ?? 'Pendiente' }}</td>
                                        <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $serialRow['updated_at'] ?? 'Pendiente' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">Esta línea todavía no contiene seriales.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end border-t border-slate-200 px-6 py-4 dark:border-white/10 sm:px-8">
                        <button wire:click="closeSerialModal" type="button" class="rounded-xl bg-violet-600 px-5 py-3 font-semibold text-white transition hover:bg-violet-500">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showFinalizeConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="finalize-request-title">
            <button wire:click="closeFinalizeConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar finalización"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-emerald-200 bg-white p-6 shadow-2xl dark:border-emerald-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>
                    </div>
                    <h3 id="finalize-request-title" class="mt-5 text-2xl font-semibold">¿Finalizar esta solicitud?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">
                        La solicitud <strong class="text-slate-950 dark:text-white">#{{ $requestId }}</strong> cambiará al estado <strong class="text-emerald-700 dark:text-emerald-300">Hecho</strong>. Después no podrás modificar sus productos, seriales ni regresar a un estado anterior.
                    </p>
                    <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button wire:click="closeFinalizeConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">Cancelar</button>
                        <button wire:click="finalize" wire:loading.attr="disabled" wire:target="finalize" type="button" class="rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-wait disabled:opacity-60 dark:bg-emerald-500 dark:hover:bg-emerald-400">
                            <span wire:loading.remove wire:target="finalize">Sí, finalizar solicitud</span>
                            <span wire:loading wire:target="finalize">Finalizando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($showDeleteConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-form-request-title">
            <button wire:click="closeDeleteConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar eliminación"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-6 shadow-2xl dark:border-rose-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.5 2.7 18a2 2 0 0 0 1.74 3h15.12a2 2 0 0 0 1.74-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/></svg>
                    </div>
                    <h3 id="delete-form-request-title" class="mt-5 text-2xl font-semibold">¿Eliminar esta solicitud?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">Se eliminará permanentemente la solicitud <strong class="text-slate-950 dark:text-white">#{{ $requestId }}</strong>. Esta acción no se puede deshacer.</p>
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
