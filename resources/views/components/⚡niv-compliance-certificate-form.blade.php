<?php

use App\Actions\NivComplianceCertificates\ParseComplianceCertificate;
use App\Actions\NivComplianceCertificates\MatchComplianceProduct;
use App\Models\NivComplianceCertificate;
use App\Models\Product;
use App\UserPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $certificateId = null;

    public string $category = 'nacional';

    public ?int $productId = null;

    #[Locked]
    public string $productMatchStatus = 'pending';

    public bool $showCreateProduct = false;

    public string $newProductName = '';

    /** @var array<string, mixed> */
    public array $fields = [];

    public bool $hasErratum = false;

    public mixed $pdfFile = null;

    #[Locked]
    public bool $hasAnalyzed = false;

    #[Locked]
    public string $extractedText = '';

    /** @var list<string> */
    #[Locked]
    public array $missingFields = [];

    public function mount(?int $certificateId = null): void
    {
        $this->certificateId = $certificateId;
        abort_unless(auth()->user()?->hasPermission($certificateId === null ? UserPermission::CreateNivComplianceCertificates : UserPermission::ViewNivComplianceCertificates), 403);
        $this->fields = array_fill_keys(array_keys(NivComplianceCertificate::fieldLabels()), '');

        if ($certificateId !== null) {
            $certificate = $this->certificate;
            $this->category = $certificate->category;
            $this->productId = $certificate->product_id;
            $this->hasErratum = $certificate->has_erratum;
            foreach ($this->fields as $key => $value) {
                $this->fields[$key] = str_ends_with($key, '_on') ? ($certificate->{$key}?->format('Y-m-d') ?? '') : ($certificate->{$key} ?? '');
            }
            if ($this->productId === null && $this->canEdit()) {
                $this->detectProduct();
            }
        }
    }

    #[Computed]
    public function certificate(): ?NivComplianceCertificate
    {
        return $this->certificateId === null ? null : NivComplianceCertificate::query()->findOrFail($this->certificateId);
    }

    public function canEdit(): bool
    {
        return auth()->user()?->hasPermission($this->certificateId === null ? UserPermission::CreateNivComplianceCertificates : UserPermission::EditNivComplianceCertificates) === true;
    }

    /** @return Collection<int, Product> */
    #[Computed]
    public function relatedProducts(): Collection
    {
        return Product::query()->where('category', $this->category)->orderBy('name')
            ->get(['id', 'name', 'category', 'brand', 'niv', 'first_value', 'second_value']);
    }

    public function updatedCategory(): void
    {
        abort_unless($this->canEdit(), 403);
        unset($this->relatedProducts);
        $this->detectProduct();
    }

    public function updatedFields(mixed $value, ?string $key = null): void
    {
        if ($key === null || in_array($key, ['brand', 'model', 'series_version', 'serial_pattern'], true)) {
            $this->detectProduct();
        }
    }

    public function updatedProductId(): void
    {
        $this->productMatchStatus = $this->productId === null ? 'pending' : 'manual';
        $this->showCreateProduct = false;
        $this->resetValidation('productId');
    }

    public function detectProduct(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->reset(['productId', 'showCreateProduct']);
        $this->resetValidation(['productId', 'newProductName']);
        if (empty($this->fields['brand']) || empty($this->fields['serial_pattern'])) {
            $this->productMatchStatus = 'pending';

            return;
        }
        $matches = app(MatchComplianceProduct::class)->handle($this->category, $this->fields);
        $this->productMatchStatus = match ($matches->count()) {
            0 => 'missing',
            1 => 'matched',
            default => 'ambiguous',
        };
        $this->productId = $matches->count() === 1 ? $matches->first()->id : null;
    }

    public function prepareProductCreation(): void
    {
        abort_unless($this->canEdit() && auth()->user()?->hasPermission(UserPermission::CreateProducts), 403);
        if (($this->certificateId === null || $this->pdfFile !== null) && ! $this->hasAnalyzed) {
            $this->addError('productId', 'Extrae los datos de la constancia antes de crear el producto.');

            return;
        }
        $this->detectProduct();
        if ($this->productMatchStatus !== 'missing') {
            return;
        }
        $this->newProductName = trim((string) ($this->fields['model'] ?: ($this->fields['series_version'] ?? '')));
        $this->showCreateProduct = true;
    }

    public function createRelatedProduct(): void
    {
        abort_unless($this->canEdit() && auth()->user()?->hasPermission(UserPermission::CreateProducts), 403);
        if (($this->certificateId === null || $this->pdfFile !== null) && ! $this->hasAnalyzed) {
            $this->addError('productId', 'Extrae los datos de la constancia antes de crear el producto.');

            return;
        }
        $this->validate([
            'category' => ['required', Rule::in(['nacional', 'importados'])],
            'fields.brand' => ['required', 'string', 'max:255'],
            'fields.serial_pattern' => ['required', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/i'],
        ]);
        $matcher = app(MatchComplianceProduct::class);
        $brand = $matcher->brand($this->fields);
        $manufacturer = match ($brand) {
            'zontes' => 'LD3',
            'morini' => 'HL4',
            default => null,
        };
        if ($brand === null || ($manufacturer !== null && ! str_starts_with((string) $matcher->code($this->fields), $manufacturer))) {
            $this->addError('productId', 'Revisa la marca y el serial patrón antes de crear el producto.');

            return;
        }
        $this->detectProduct();
        if ($this->productMatchStatus !== 'missing') {
            return;
        }
        $this->newProductName = trim($this->newProductName);
        $this->showCreateProduct = true;
        $this->validate(['newProductName' => ['required', 'string', 'max:255', Rule::unique((new Product)->getTable(), 'name')]], [
            'newProductName.unique' => 'Ya existe un producto con esa descripción. Revisa su categoría, marca y código en Productos.',
            'newProductName.required' => 'Ingresa la descripción del producto.',
        ]);
        $code = $matcher->code($this->fields);
        $product = Product::query()->create([
            'name' => $this->newProductName,
            'category' => $this->category,
            'brand' => $brand,
            'first_value' => $brand === 'bera' ? substr((string) $code, 0, 1) : substr((string) $code, 0, 3),
            'second_value' => $brand === 'bera' ? substr((string) $code, 1, 1) : substr((string) $code, 3, 5),
            'niv' => $code,
        ]);
        $this->productId = $product->id;
        $this->productMatchStatus = 'created';
        $this->showCreateProduct = false;
        unset($this->relatedProducts);
    }

    public function updatedPdfFile(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->reset(['hasAnalyzed', 'extractedText', 'missingFields']);
        $this->reset(['productId', 'productMatchStatus', 'showCreateProduct', 'newProductName']);
        $this->resetValidation();
    }

    public function analyze(ParseComplianceCertificate $parser): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['category' => ['required', Rule::in(['nacional', 'importados'])], 'pdfFile' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        $this->reset(['hasAnalyzed', 'extractedText', 'missingFields']);

        try {
            $result = $parser->handle($this->pdfFile->getRealPath());
        } catch (\RuntimeException $exception) {
            $this->addError('pdfFile', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('pdfFile', 'No fue posible analizar la constancia. Verifica que el PDF sea legible y no esté protegido.');

            return;
        }

        foreach (NivComplianceCertificate::fieldLabels() as $key => $label) {
            $this->fields[$key] = $result['fields'][$key] ?? '';
        }
        $this->hasErratum = (bool) ($result['fields']['has_erratum'] ?? false);
        $this->missingFields = array_values(array_intersect($result['missing_fields'], array_keys(NivComplianceCertificate::fieldLabels())));
        $this->extractedText = $result['text'];
        $this->hasAnalyzed = true;
        $this->resetValidation();
        $this->detectProduct();
    }

    /** @return array<string, list<string>> */
    public function fieldGroups(): array
    {
        return [
            'Identificación de la constancia' => ['certificate_number', 'document_reference', 'company_name', 'company_rif', 'refeciv'],
            'Datos del vehículo' => ['brand', 'model', 'series_version', 'serial_pattern', 'manufacturing_years', 'model_year', 'country_of_origin', 'vehicle_class', 'vehicle_type', 'vehicle_use', 'wmi', 'iim'],
            'Vigencia y observaciones' => ['issued_on', 'expires_on', 'observations'],
            'Fe de errata' => ['original_series_version', 'erratum_reference', 'erratum_issued_on', 'erratum_notes'],
        ];
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);
        $rules = [
            'category' => ['required', Rule::in(['nacional', 'importados'])],
            'productId' => ['nullable', 'integer', Rule::exists((new Product)->getTable(), 'id')->where('category', $this->category)],
            'hasErratum' => ['boolean'],
            'pdfFile' => [Rule::requiredIf($this->certificateId === null), 'nullable', 'file', 'mimes:pdf', 'max:20480'],
        ];
        foreach (NivComplianceCertificate::fieldLabels() as $key => $label) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:255'];
        }
        foreach (['certificate_number', 'brand', 'model'] as $key) {
            $rules['fields.'.$key][0] = 'required';
        }
        $this->fields['serial_pattern'] = Str::upper(trim((string) ($this->fields['serial_pattern'] ?? '')));
        $this->fields['wmi'] = Str::upper(trim((string) ($this->fields['wmi'] ?? '')));
        $rules['fields.serial_pattern'] = ['required', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'];
        $rules['fields.wmi'] = ['nullable', 'string', 'size:3'];
        $rules['fields.model_year'] = ['nullable', 'integer', 'between:1900,2100'];
        foreach (['issued_on', 'expires_on', 'erratum_issued_on'] as $key) {
            $rules['fields.'.$key] = ['nullable', 'date_format:Y-m-d'];
        }
        if (($this->fields['issued_on'] ?? '') !== '') {
            $rules['fields.expires_on'][] = 'after_or_equal:fields.issued_on';
        }
        foreach (['observations', 'erratum_notes'] as $key) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:5000'];
        }
        $validated = $this->validate($rules, ['fields.serial_pattern.regex' => 'El serial patrón debe tener 17 caracteres válidos para un NIV, sin I, O ni Q.']);

        if ($this->productId !== null && ! app(MatchComplianceProduct::class)->matches(Product::query()->findOrFail($this->productId), $this->category, $validated['fields'])) {
            $this->addError('productId', 'El producto debe coincidir con la categoría, la marca y el código del modelo de la constancia.');

            return;
        }

        if ($this->pdfFile !== null && ! $this->hasAnalyzed) {
            $this->addError('pdfFile', 'Extrae los datos del PDF antes de guardar.');

            return;
        }

        $values = array_intersect_key($validated['fields'], NivComplianceCertificate::fieldLabels());
        $values = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $values);
        $values['category'] = $validated['category'];
        $values['product_id'] = $validated['productId'];
        $values['has_erratum'] = $validated['hasErratum'];
        if (! $validated['hasErratum']) {
            foreach ($this->fieldGroups()['Fe de errata'] as $key) {
                $values[$key] = null;
            }
        }

        $newPath = null;
        $oldPath = $this->certificate?->file_path;
        if ($this->pdfFile !== null) {
            $hash = hash_file('sha256', $this->pdfFile->getRealPath());
            if (NivComplianceCertificate::query()->where('file_hash', $hash)->when($this->certificateId !== null, fn ($query) => $query->whereKeyNot($this->certificateId))->exists()) {
                $this->addError('pdfFile', 'Este archivo ya está registrado como constancia de cumplimiento NIV.');

                return;
            }
            $values['file_hash'] = $hash;
            $values['original_file_name'] = Str::limit(basename($this->pdfFile->getClientOriginalName()), 255, '');
            $values['extracted_text'] = $this->extractedText;
        }

        try {
            if ($this->pdfFile !== null) {
                $newPath = $this->pdfFile->store('niv-compliance-certificates', 'local');
                $values['file_path'] = $newPath;
            }
            $certificate = DB::transaction(function () use ($values): NivComplianceCertificate {
                if ($this->certificateId === null) {
                    return NivComplianceCertificate::query()->create([...$values, 'uploaded_by' => auth()->id()]);
                }
                $certificate = NivComplianceCertificate::query()->findOrFail($this->certificateId);
                $certificate->update($values);

                return $certificate;
            });
        } catch (\Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }
            report($exception);
            $this->addError('pdfFile', 'No fue posible guardar la constancia. Inténtalo nuevamente.');

            return;
        }

        if ($newPath !== null && $oldPath !== null && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }
        session()->flash('status', 'Constancia de cumplimiento NIV guardada correctamente.');
        $this->redirectRoute('niv_compliance_certificates.edit', ['certificate' => $certificate], navigate: true);
    }
};
?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <a href="{{ route('niv_compliance_certificates.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-4 py-3 font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">Volver a la lista</a>
        @if ($certificateId)
            <a href="{{ route('niv_compliance_certificates.download', $certificateId) }}" class="rounded-xl bg-slate-900 px-5 py-3 font-semibold text-white dark:bg-white dark:text-slate-900">Descargar PDF original</a>
        @endif
    </div>

    @if (session('status'))
        <p role="status" class="mb-6 rounded-2xl bg-emerald-50 px-5 py-4 font-medium text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/[0.04] sm:p-8">
            <h2 class="text-2xl font-semibold">{{ $certificateId ? 'Constancia registrada' : 'Nueva constancia de cumplimiento NIV' }}</h2>
            <p class="mt-2 text-slate-500 dark:text-slate-400">Selecciona la categoría y carga la constancia. Revisa los datos extraídos antes de guardar.</p>
            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <div>
                    <label for="compliance-category" class="mb-2 block text-sm font-semibold">Categoría</label>
                    <select id="compliance-category" wire:model.live="category" @disabled(! $this->canEdit()) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/60">
                        <option value="nacional">Nacional</option>
                        <option value="importados">Importados</option>
                    </select>
                    @error('category') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="compliance-product" class="mb-2 block text-sm font-semibold">Producto relacionado</label>
                    <select id="compliance-product" wire:model.live="productId" @disabled(! $this->canEdit()) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/60">
                        <option value="">Sin producto relacionado</option>
                        @foreach ($this->relatedProducts as $product)
                            <option wire:key="compliance-product-option-{{ $product->id }}" value="{{ $product->id }}">{{ $product->name }} · {{ ucfirst($product->brand) }}{{ $product->niv ? ' · '.$product->niv : '' }}</option>
                        @endforeach
                    </select>
                    @error('productId') <p role="alert" class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    @if (in_array($productMatchStatus, ['matched', 'created'], true))
                        <p role="status" class="mt-2 text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ $productMatchStatus === 'created' ? 'Producto creado y seleccionado. Guarda la constancia para conservar la relación.' : 'Producto coincidente encontrado en la categoría seleccionada.' }}</p>
                    @elseif ($productMatchStatus === 'missing')
                        <p role="status" class="mt-2 text-sm text-amber-700 dark:text-amber-300">No se encontró un producto coincidente en esta categoría.</p>
                        @if ($this->canEdit() && auth()->user()->hasPermission(UserPermission::CreateProducts) && ! $showCreateProduct)
                            <button wire:click="prepareProductCreation" wire:loading.attr="disabled" type="button" class="mt-3 rounded-lg border border-indigo-200 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50 dark:border-indigo-500/30 dark:text-indigo-300 dark:hover:bg-indigo-500/10">Crear producto desde esta constancia</button>
                        @endif
                    @elseif ($productMatchStatus === 'ambiguous')
                        <p role="status" class="mt-2 text-sm text-amber-700 dark:text-amber-300">Hay varios productos coincidentes. Selecciona el producto relacionado para evitar duplicarlo.</p>
                    @elseif ($productMatchStatus === 'pending')
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Al extraer el PDF se buscará el producto por categoría, marca y código del modelo.</p>
                    @endif
                    @if ($productId !== null && auth()->user()->hasPermission(UserPermission::ViewProducts))
                        <a href="{{ route('products.edit', $productId) }}" target="_blank" rel="noopener" class="mt-2 inline-block text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Consultar producto en otra pestaña</a>
                    @endif
                </div>
            </div>
            @if ($showCreateProduct && $this->canEdit() && auth()->user()->hasPermission(UserPermission::CreateProducts))
                <div class="mt-5 rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-500/30 dark:bg-indigo-500/5">
                    <h3 class="font-semibold">Nuevo producto desde la constancia</h3>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Categoría: {{ $category === 'importados' ? 'Importados' : 'Nacional' }} · Marca: {{ $fields['brand'] }} · Código del modelo: {{ app(MatchComplianceProduct::class)->code($fields) ?? 'Sin código automático' }}</p>
                    <div class="mt-4 max-w-xl">
                        <label for="compliance-new-product-name" class="mb-2 block text-sm font-semibold">Descripción del producto</label>
                        <input id="compliance-new-product-name" wire:model="newProductName" type="text" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-slate-950/60">
                        @error('newProductName') <p role="alert" class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                    </div>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <button wire:click="createRelatedProduct" wire:loading.attr="disabled" type="button" class="rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">Crear y seleccionar producto</button>
                        <button wire:click="$set('showCreateProduct', false)" type="button" class="rounded-xl border border-slate-200 px-4 py-2 font-semibold text-slate-600 dark:border-white/10 dark:text-slate-300">Cancelar</button>
                    </div>
                </div>
            @endif
            @if ($this->canEdit())
                <div class="mt-6 flex flex-col gap-4 rounded-2xl border border-dashed border-indigo-300 bg-indigo-50/60 p-5 dark:border-indigo-500/30 dark:bg-indigo-500/5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <label for="compliance-pdf" class="mb-2 block font-semibold">{{ $certificateId ? 'Reemplazar constancia PDF' : 'Constancia PDF' }}</label>
                        <input id="compliance-pdf" wire:model="pdfFile" type="file" accept=".pdf,application/pdf" class="block w-full text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:font-semibold file:text-white">
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Hasta 20 MB y 10 páginas. Incluye la fe de errata en el mismo archivo, si corresponde.</p>
                    </div>
                    <button wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze,pdfFile" type="button" @disabled($pdfFile === null) class="shrink-0 rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="analyze">Extraer datos del PDF</span>
                        <span wire:loading wire:target="analyze">Leyendo constancia...</span>
                    </button>
                </div>
                <p wire:loading wire:target="pdfFile" role="status" class="mt-3 text-sm font-medium text-indigo-600 dark:text-indigo-300">Cargando PDF...</p>
                <p wire:loading wire:target="analyze" role="status" class="mt-3 text-sm font-medium text-indigo-600 dark:text-indigo-300">Reconociendo el texto y revisando todas las páginas. Este proceso puede tardar unos segundos.</p>
            @endif
            @error('pdfFile') <p role="alert" class="mt-3 rounded-xl bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-200">{{ $message }}</p> @enderror
            @if ($hasAnalyzed)
                <div role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
                    Datos extraídos. Revisa la información y completa los campos que no se pudieron leer.
                    @if ($missingFields !== [])
                        <p class="mt-2">Pendientes de lectura: {{ collect($missingFields)->map(fn ($key) => NivComplianceCertificate::fieldLabels()[$key])->join(', ') }}.</p>
                    @endif
                </div>
            @endif
        </section>

        <div x-data="{ activeTab: 0 }" class="rounded-3xl border border-slate-200 bg-white dark:border-white/10 dark:bg-white/[0.04]">
            <div role="tablist" aria-label="Secciones de la constancia" class="flex gap-2 overflow-x-auto border-b border-slate-200 p-3 dark:border-white/10">
                @foreach ($this->fieldGroups() as $group => $keys)
                    <button
                        wire:key="compliance-tab-{{ $loop->index }}"
                        id="compliance-tab-{{ $loop->index }}"
                        type="button"
                        role="tab"
                        aria-controls="compliance-panel-{{ $loop->index }}"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                        tabindex="{{ $loop->first ? '0' : '-1' }}"
                        x-bind:aria-selected="activeTab === {{ $loop->index }}"
                        x-bind:tabindex="activeTab === {{ $loop->index }} ? 0 : -1"
                        x-on:click="activeTab = {{ $loop->index }}"
                        x-on:keydown.right.prevent="activeTab = (activeTab + 1) % {{ $loop->count }}; $nextTick(() => $el.parentElement.children[activeTab].focus())"
                        x-on:keydown.left.prevent="activeTab = (activeTab + {{ $loop->count - 1 }}) % {{ $loop->count }}; $nextTick(() => $el.parentElement.children[activeTab].focus())"
                        x-on:keydown.home.prevent="activeTab = 0; $nextTick(() => $el.parentElement.children[activeTab].focus())"
                        x-on:keydown.end.prevent="activeTab = {{ $loop->count - 1 }}; $nextTick(() => $el.parentElement.children[activeTab].focus())"
                        x-bind:class="activeTab === {{ $loop->index }} ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-white'"
                        class="shrink-0 rounded-xl px-4 py-3 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
                    >
                        {{ $group }}
                        @if ($errors->hasAny(array_map(fn ($key) => 'fields.'.$key, $keys)))
                            <span class="ml-2 rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-700 dark:bg-rose-500/15 dark:text-rose-200">Revisar</span>
                        @endif
                    </button>
                @endforeach
            </div>

            @if ($errors->has('fields.*'))
                <p role="alert" class="mx-6 mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-200 sm:mx-8">Revisa los campos de las pestañas marcadas antes de guardar.</p>
            @endif

            @foreach ($this->fieldGroups() as $group => $keys)
                <section
                    wire:key="compliance-group-{{ $loop->index }}"
                    id="compliance-panel-{{ $loop->index }}"
                    role="tabpanel"
                    aria-labelledby="compliance-tab-{{ $loop->index }}"
                    tabindex="0"
                    x-show="activeTab === {{ $loop->index }}"
                    @if (! $loop->first) x-cloak @endif
                    class="rounded-b-3xl p-6 focus-visible:outline-2 focus-visible:outline-indigo-500 sm:p-8"
                >
                    <h3 class="text-lg font-semibold">{{ $group }}</h3>
                    @if ($group === 'Fe de errata')
                        <label class="mt-4 inline-flex items-center gap-3 text-sm font-medium"><input wire:model.live="hasErratum" type="checkbox" @disabled(! $this->canEdit()) class="size-4 rounded border-slate-300 text-indigo-600">La constancia incluye una fe de errata</label>
                    @endif
                    @if ($group !== 'Fe de errata' || $hasErratum)
                        <div class="mt-5 grid gap-5 md:grid-cols-2">
                            @foreach ($keys as $key)
                                <div wire:key="compliance-field-{{ $key }}" @class(['md:col-span-2' => in_array($key, ['observations', 'erratum_notes'], true)])>
                                    <label for="compliance-{{ $key }}" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ NivComplianceCertificate::fieldLabels()[$key] }}</label>
                                    @if (in_array($key, ['observations', 'erratum_notes'], true))
                                        <textarea id="compliance-{{ $key }}" wire:model="fields.{{ $key }}" rows="3" maxlength="5000" @disabled(! $this->canEdit()) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 disabled:opacity-60 dark:border-white/10 dark:bg-slate-950/60"></textarea>
                                    @else
                                        <input id="compliance-{{ $key }}" @if (in_array($key, ['brand', 'model', 'series_version', 'serial_pattern'], true)) wire:model.blur="fields.{{ $key }}" @else wire:model="fields.{{ $key }}" @endif type="{{ str_ends_with($key, '_on') ? 'date' : ($key === 'model_year' ? 'number' : 'text') }}" @disabled(! $this->canEdit()) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 disabled:opacity-60 dark:border-white/10 dark:bg-slate-950/60">
                                    @endif
                                    @if ($key === 'serial_pattern' && strlen($fields['serial_pattern'] ?? '') === 17)
                                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Código del modelo{{ app(MatchComplianceProduct::class)->brand($fields) === 'bera' ? ' (posiciones 4 y 5)' : '' }}: <span class="font-mono font-semibold">{{ app(MatchComplianceProduct::class)->code($fields) ?? 'Pendiente de reconocer la marca' }}</span></p>
                                    @endif
                                    @error('fields.'.$key) <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        @if ($this->canEdit())
            <div class="flex justify-end"><button type="submit" wire:loading.attr="disabled" wire:target="save,analyze,pdfFile" class="rounded-xl bg-indigo-600 px-6 py-3 font-semibold text-white transition hover:bg-indigo-500 disabled:opacity-50">Guardar constancia</button></div>
        @endif
    </form>
</div>
