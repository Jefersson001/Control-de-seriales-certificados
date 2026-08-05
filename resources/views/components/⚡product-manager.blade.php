<?php

use App\Models\Product;
use App\Actions\Products\ImportProducts;
use App\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;
    use WithFileUploads;

    public string $search = '';

    public $perPage = 10;

    public bool $showForm = false;

    #[Locked]
    public bool $formOnly = false;

    #[Locked]
    public bool $formReadOnly = false;

    public bool $showDeleteConfirmation = false;

    #[Locked]
    public ?int $editingProductId = null;

    #[Locked]
    public ?int $deletingProductId = null;

    #[Locked]
    public string $deletingProductName = '';

    public string $description = '';

    public string $firstValue = '';

    public string $secondValue = '';

    public string $niv = '';

    public $importFile;

    public ?string $status = null;

    public ?string $errorMessage = null;

    public function mount(bool $formOnly = false, ?int $productId = null): void
    {
        $this->authorizePermission(UserPermission::ViewProducts);
        $this->formOnly = $formOnly;
        $this->status = session('status');

        if (! $formOnly) {
            return;
        }

        if ($productId === null) {
            $this->authorizePermission(UserPermission::CreateProducts);
            $this->showForm = true;

            return;
        }

        $product = Product::query()->findOrFail($productId);
        $this->loadProduct($product);
        $this->formReadOnly = ! $this->canEditProducts();
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
    public function products(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Product::query()
            ->select(['id', 'name', 'first_value', 'second_value', 'niv', 'year', 'created_at', 'updated_at'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('first_value', 'like', "%{$search}%")
                        ->orWhere('second_value', 'like', "%{$search}%")
                        ->orWhere('niv', 'like', "%{$search}%")
                        ->when(ctype_digit($search), fn (Builder $query) => $query->orWhere('year', (int) $search));
                });
            })
            ->latest()
            ->paginate((int) $this->perPage);
    }

    public function openCreateForm(): void
    {
        $this->authorizePermission(UserPermission::CreateProducts);
        $this->resetForm();
        $this->showForm = true;
    }

    public function editProduct(int $productId): void
    {
        $this->authorizePermission(UserPermission::EditProducts);

        $product = Product::query()->findOrFail($productId);

        $this->loadProduct($product);
    }

    public function closeForm(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $requiredPermission = $this->editingProductId === null
            ? UserPermission::CreateProducts
            : UserPermission::EditProducts;
        $this->authorizePermission($requiredPermission);

        $this->description = trim($this->description);
        $nameRule = Rule::unique((new Product)->getTable(), 'name');

        if ($this->editingProductId !== null) {
            $nameRule->ignore($this->editingProductId);
        }

        $validated = $this->validate([
            'description' => ['required', 'string', 'max:255', $nameRule],
            'firstValue' => ['nullable', 'string', 'max:255'],
            'secondValue' => ['nullable', 'string', 'max:255'],
            'niv' => ['nullable', 'string', 'max:50'],
        ], [
            'description.required' => 'Ingresa la descripción del producto.',
            'description.unique' => 'Ya existe un producto con esta descripción.',
            'description.max' => 'La descripción no puede superar los 255 caracteres.',
            'firstValue.max' => 'El campo 1ero no puede superar los 255 caracteres.',
            'secondValue.max' => 'El campo 2do no puede superar los 255 caracteres.',
            'niv.max' => 'El NIV no puede superar los 50 caracteres.',
        ]);

        $productData = [
            'name' => $validated['description'],
            'first_value' => $validated['firstValue'] ?: null,
            'second_value' => $validated['secondValue'] ?: null,
            'niv' => $validated['niv'] ?: null,
        ];

        if ($this->editingProductId !== null) {
            $product = Product::query()->findOrFail($this->editingProductId);
            $product->update($productData);
            $message = 'Producto actualizado correctamente.';
        } else {
            $product = Product::create($productData);
            $message = 'Producto creado correctamente.';
            $this->resetPage();
        }

        unset($this->products);
        if ($this->formOnly) {
            session()->flash('status', $message);
            $this->redirectRoute('products.edit', ['product' => $product], navigate: true);

            return;
        }

        $this->resetForm();
        $this->status = $message;
    }

    public function import(ImportProducts $importProducts): void
    {
        $this->authorizePermission(UserPermission::CreateProducts);

        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ], [
            'importFile.required' => 'Selecciona el archivo de Excel que deseas importar.',
            'importFile.mimes' => 'El archivo debe tener formato XLSX.',
            'importFile.max' => 'El archivo no puede superar los 10 MB.',
        ]);

        try {
            $result = $importProducts->handle($this->importFile->getRealPath());
        } catch (\RuntimeException $exception) {
            $this->addError('importFile', $exception->getMessage());

            return;
        }

        $this->reset('importFile');
        $this->resetPage();
        unset($this->products);
        $this->status = "Importación completada: {$result['imported']} registros cargados y {$result['skipped']} omitidos.";
    }

    public function openDeleteConfirmation(int $productId): void
    {
        $this->authorizePermission(UserPermission::DeleteProducts);
        $this->errorMessage = null;

        $product = Product::query()->findOrFail($productId);

        $this->deletingProductId = $product->id;
        $this->deletingProductName = $product->name;
        $this->showDeleteConfirmation = true;
    }

    public function closeDeleteConfirmation(): void
    {
        $this->reset([
            'showDeleteConfirmation',
            'deletingProductId',
            'deletingProductName',
        ]);
    }

    public function deleteProduct(): void
    {
        $this->authorizePermission(UserPermission::DeleteProducts);

        $product = Product::query()->findOrFail($this->deletingProductId);

        if ($product->motorcycleSerialRequestLines()->exists()) {
            $this->closeDeleteConfirmation();
            $this->status = null;
            $this->errorMessage = 'No se puede eliminar este producto porque está relacionado con una o más solicitudes de seriales de motos.';

            return;
        }

        $product->delete();
        $this->errorMessage = null;

        if ($this->formOnly) {
            session()->flash('status', 'Producto eliminado correctamente.');
            $this->redirectRoute('products.index', navigate: true);

            return;
        }

        $this->closeDeleteConfirmation();
        $this->resetPage();
        unset($this->products);
        $this->status = 'Producto eliminado correctamente.';
    }

    public function canEditProducts(): bool
    {
        return auth()->user()?->hasPermission(UserPermission::EditProducts) === true;
    }

    public function canDeleteProducts(): bool
    {
        return auth()->user()?->hasPermission(UserPermission::DeleteProducts) === true;
    }

    private function resetForm(): void
    {
        $this->reset([
            'showForm',
            'editingProductId',
            'description',
            'firstValue',
            'secondValue',
            'niv',
            'status',
            'errorMessage',
        ]);
        $this->resetValidation();
    }

    private function loadProduct(Product $product): void
    {
        $this->resetValidation();
        $this->editingProductId = $product->id;
        $this->description = $product->name;
        $this->firstValue = $product->first_value ?? '';
        $this->secondValue = $product->second_value ?? '';
        $this->niv = $product->niv ?? '';
        if (! $this->formOnly) {
            $this->status = null;
        }
        $this->showForm = true;
    }

    private function authorizePermission(UserPermission $permission): void
    {
        abort_unless(auth()->user()?->hasPermission($permission), 403);
    }
};
?>

<div>
    @unless ($formOnly)
    <div class="mb-8 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-600 dark:text-sky-300">Administración</p>
            <h2 class="mt-3 text-3xl font-semibold tracking-tight">Productos</h2>
            <p class="mt-3 max-w-2xl leading-7 text-slate-600 dark:text-slate-400">
                Consulta, busca y administra los productos registrados en el sistema.
            </p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3">
            @if (auth()->user()->hasPermission(UserPermission::CreateProducts))
                <label class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-500">
                    <input wire:model="importFile" type="file" accept=".xlsx" class="sr-only">
                    Importar Excel
                </label>
            @endif
            <a href="{{ route('products.export', ['search' => trim($search)]) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-slate-950 bg-white px-5 py-3 font-bold text-slate-950 transition hover:bg-slate-950 hover:text-white dark:border-white dark:bg-slate-900 dark:text-white dark:hover:bg-white dark:hover:text-slate-950">Exportar data</a>
            @if (auth()->user()->hasPermission(UserPermission::CreateProducts))
                <a href="{{ route('products.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl bg-sky-600 px-5 py-3 font-semibold text-white shadow-lg shadow-sky-500/25 transition hover:bg-sky-500 focus:outline-none focus:ring-4 focus:ring-sky-500/30">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Crear producto
                </a>
            @endif
        </div>
    </div>

    @if ($status)
        <div role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $status }}
        </div>
    @endif

    @if ($errorMessage)
        <div role="alert" class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
            {{ $errorMessage }}
        </div>
    @endif

    @if ($importFile)
        <div class="mb-6 flex flex-col gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-indigo-400/20 dark:bg-indigo-500/10">
            <div>
                <p class="font-semibold text-indigo-950 dark:text-indigo-100">{{ $importFile->getClientOriginalName() }}</p>
                <p class="mt-1 text-sm text-indigo-700 dark:text-indigo-300">Se cargarán las columnas Descripción, 1ero, 2do, NIV y Año.</p>
            </div>
            <button wire:click="import" wire:loading.attr="disabled" wire:target="import,importFile" type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-wait disabled:opacity-60">
                <span wire:loading.remove wire:target="import">Confirmar importación</span>
                <span wire:loading wire:target="import">Importando...</span>
            </button>
        </div>
    @endif

    @error('importFile')
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</div>
    @enderror

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full max-w-lg">
                <label for="product-search" class="sr-only">Buscar productos</label>
                <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/>
                </svg>
                <input id="product-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Buscar por descripción, NIV, año..." class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-12 pr-4 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:ring-4 focus:ring-sky-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                <span wire:loading wire:target="search" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-medium text-sky-600 dark:text-sky-300">Buscando...</span>
                </div>
            </div>
            <x-per-page-selector id="products-per-page" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl text-left">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Descripción</th>
                        <th class="px-6 py-4 font-semibold">1ero</th>
                        <th class="px-6 py-4 font-semibold">2do</th>
                        <th class="px-6 py-4 font-semibold">NIV</th>
                        <th class="px-6 py-4 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->products as $product)
                        <tr wire:key="product-{{ $product->id }}" x-on:click="window.location.href = '{{ route('products.edit', $product) }}'" class="cursor-pointer transition hover:bg-sky-50/60 dark:hover:bg-sky-500/5">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">
                                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8.25 4.5L12 12 3.75 7.5 12 3Z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 7.5V16.5L12 21m0-9v9m8.25-13.5V16.5L12 21"/>
                                        </svg>
                                    </span>
                                    <span class="font-semibold">{{ $product->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm">{{ $product->first_value ?: '—' }}</td>
                            <td class="px-6 py-4 text-sm">{{ $product->second_value ?: '—' }}</td>
                            <td class="px-6 py-4 text-sm font-medium">{{ $product->niv ?: '—' }}</td>
                            <td class="px-6 py-4 text-right">
                                <div class="inline-flex items-center justify-end gap-1">
                                    @if ($this->canDeleteProducts())
                                        <button wire:click.stop="openDeleteConfirmation({{ $product->id }})" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-100 dark:text-rose-300 dark:hover:bg-rose-500/10">Eliminar</button>
                                    @endif
                                    @if (! $this->canEditProducts() && ! $this->canDeleteProducts())
                                        <a href="{{ route('products.edit', $product) }}" wire:navigate x-on:click.stop class="rounded-lg px-3 py-2 text-sm text-slate-500 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5">Solo lectura · Ver</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-14 text-center">
                                <p class="font-semibold">No se encontraron productos</p>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Crea un producto o prueba con otra búsqueda.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->products->hasPages())
            <div class="border-t border-slate-200 px-6 py-4 dark:border-white/10">{{ $this->products->links() }}</div>
        @endif
    </div>
    @endunless

    @if ($showForm)
        @if ($formOnly)
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <a href="{{ route('products.index') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                        Volver a la lista
                    </a>
                    <span class="text-sm text-slate-500 dark:text-slate-400">{{ $editingProductId ? "Producto #{$editingProductId}" : 'Nuevo producto' }}</span>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    @if ($editingProductId && $this->canDeleteProducts())
                        <button wire:click="openDeleteConfirmation({{ $editingProductId }})" type="button" class="rounded-xl border border-rose-200 bg-white px-5 py-3 font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:bg-transparent dark:text-rose-300 dark:hover:bg-rose-500/10">Eliminar</button>
                    @endif
                    @unless ($formReadOnly)
                        <button wire:click="save" wire:loading.attr="disabled" wire:target="save" type="button" class="rounded-xl bg-sky-600 px-6 py-3 font-semibold text-white shadow-lg shadow-sky-500/25 transition hover:bg-sky-500 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="save">Guardar</span>
                            <span wire:loading wire:target="save">Guardando...</span>
                        </button>
                    @else
                        <span class="rounded-full bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">Solo lectura</span>
                    @endunless
                </div>
            </div>

            @if ($status)
                <div role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $status }}</div>
            @endif

            @if ($errorMessage)
                <div role="alert" class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">{{ $errorMessage }}</div>
            @endif
        @endif

        <div @class(['fixed inset-0 z-50 overflow-y-auto' => ! $formOnly]) role="dialog" aria-labelledby="product-form-title">
            @unless ($formOnly)
                <button wire:click="closeForm" type="button" class="fixed inset-0 cursor-default bg-slate-950/65 backdrop-blur-sm" aria-label="Cerrar formulario"></button>
            @endunless
            <div @class(['relative', 'flex min-h-full items-center justify-center p-4' => ! $formOnly])>
                <form wire:submit="save" class="relative w-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.04] sm:p-8 {{ $formOnly ? '' : 'max-w-lg shadow-2xl dark:bg-slate-900' }}">
                    @unless ($formOnly)
                    <div class="mb-7 flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-600 dark:text-sky-300">{{ $editingProductId ? 'Edición' : 'Nuevo registro' }}</p>
                            <h3 id="product-form-title" class="mt-2 text-2xl font-semibold">{{ $editingProductId ? 'Modificar producto' : 'Crear producto' }}</h3>
                        </div>
                        <a href="{{ route('products.index') }}" wire:navigate class="grid size-10 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 dark:hover:bg-white/5 dark:hover:text-white" aria-label="Volver a productos">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M6 18 18 6M6 6l12 12"/></svg>
                        </a>
                    </div>
                    @endunless

                    @if ($formOnly)
                        <div class="mb-7 border-b border-slate-200 pb-6 dark:border-white/10">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-600 dark:text-sky-300">Información del producto</p>
                            <h3 id="product-form-title" class="mt-2 text-2xl font-semibold">{{ $editingProductId ? 'Producto registrado' : 'Nuevo producto' }}</h3>
                        </div>
                    @endif

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="product-description" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Descripción</label>
                            <input id="product-description" wire:model="description" type="text" maxlength="255" required autofocus @disabled($formReadOnly) class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 focus:border-sky-400 focus:ring-4 focus:ring-sky-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white dark:disabled:bg-slate-950/30">
                            @error('description') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                        </div>
                        @foreach ([['firstValue', '1ero'], ['secondValue', '2do'], ['niv', 'NIV']] as [$field, $label])
                            <div>
                                <label for="product-{{ $field }}" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ $label }}</label>
                                <input id="product-{{ $field }}" wire:model="{{ $field }}" type="text" maxlength="{{ $field === 'niv' ? 50 : 255 }}" @disabled($formReadOnly) class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 focus:border-sky-400 focus:ring-4 focus:ring-sky-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white dark:disabled:bg-slate-950/30">
                                @error($field) <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>

                    @unless ($formOnly)
                    <div class="mt-8 flex flex-col-reverse gap-3 border-t border-slate-200 pt-6 sm:flex-row sm:justify-end dark:border-white/10">
                        <a href="{{ route('products.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-5 py-3 text-center font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">{{ $formReadOnly ? 'Volver' : 'Cancelar' }}</a>
                        @unless ($formReadOnly)
                        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="rounded-xl bg-sky-600 px-6 py-3 font-semibold text-white shadow-lg shadow-sky-500/25 transition hover:bg-sky-500 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="save">{{ $editingProductId ? 'Guardar cambios' : 'Crear producto' }}</span>
                            <span wire:loading wire:target="save">Guardando...</span>
                        </button>
                        @endunless
                    </div>
                    @endunless
                </form>
            </div>
        </div>
    @endif

    @if ($showDeleteConfirmation)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-product-title">
            <button wire:click="closeDeleteConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar eliminación"></button>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-6 shadow-2xl dark:border-rose-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.5 2.7 18a2 2 0 0 0 1.74 3h15.12a2 2 0 0 0 1.74-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/></svg>
                    </div>
                    <h3 id="delete-product-title" class="mt-5 text-2xl font-semibold">¿Eliminar este producto?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">
                        Se eliminará permanentemente <strong class="text-slate-950 dark:text-white">{{ $deletingProductName }}</strong>. Esta acción no se puede deshacer.
                    </p>
                    <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button wire:click="closeDeleteConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">Cancelar</button>
                        <button wire:click="deleteProduct" wire:loading.attr="disabled" wire:target="deleteProduct" type="button" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60 dark:bg-rose-500 dark:hover:bg-rose-400">
                            <span wire:loading.remove wire:target="deleteProduct">Sí, eliminar producto</span>
                            <span wire:loading wire:target="deleteProduct">Eliminando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
