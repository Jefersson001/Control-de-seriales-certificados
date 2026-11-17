<?php

use App\Models\NivComplianceCertificate;
use App\Models\Product;
use App\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url(as: 'category', except: 'all')]
    public string $categoryFilter = 'all';

    #[Url(as: 'product', except: '')]
    public string $productFilter = '';

    public int $perPage = 10;

    #[Locked]
    public ?int $deletingCertificateId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewNivComplianceCertificates), 403);
        if (! in_array($this->categoryFilter, ['all', 'nacional', 'importados'], true)) {
            $this->categoryFilter = 'all';
        }
        $this->normalizeProductFilter();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        if (! in_array($this->categoryFilter, ['all', 'nacional', 'importados'], true)) {
            $this->categoryFilter = 'all';
        }
        $this->resetPage();
    }

    public function updatedPerPage(mixed $value): void
    {
        $this->perPage = max(1, min((int) $value, 10000));
        $this->resetPage();
    }

    public function updatedProductFilter(): void
    {
        $this->normalizeProductFilter();
        $this->resetPage();
    }

    private function normalizeProductFilter(): void
    {
        if ($this->productFilter !== '' && filter_var($this->productFilter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->productFilter = '0';
        }
    }

    /** @return Collection<int, Product> */
    #[Computed]
    public function filterProducts(): Collection
    {
        return Product::query()->orderBy('name')->get(['id', 'name', 'brand']);
    }

    #[Computed]
    public function certificates(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return NivComplianceCertificate::query()
            ->select(['id', 'category', 'certificate_number', 'brand', 'model', 'series_version', 'issued_on', 'expires_on', 'has_erratum'])
            ->when(in_array($this->categoryFilter, ['nacional', 'importados'], true), fn (Builder $query) => $query->where('category', $this->categoryFilter))
            ->when($this->productFilter !== '', fn (Builder $query) => $query->where('product_id', $this->productFilter))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('certificate_number', 'like', "%{$search}%")->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")->orWhere('series_version', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")->orWhere('serial_pattern', 'like', "%{$search}%");
            }))
            ->latest('id')->paginate($this->perPage);
    }

    public function confirmDeletion(int $certificateId): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteNivComplianceCertificates), 403);
        NivComplianceCertificate::query()->findOrFail($certificateId);
        $this->deletingCertificateId = $certificateId;
    }

    public function cancelDeletion(): void
    {
        $this->deletingCertificateId = null;
    }

    public function deleteCertificate(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteNivComplianceCertificates), 403);
        abort_if($this->deletingCertificateId === null, 404);
        $certificate = NivComplianceCertificate::query()->findOrFail($this->deletingCertificateId);
        $path = $certificate->file_path;
        $certificate->delete();
        Storage::disk('local')->delete($path);
        $this->cancelDeletion();
        $this->resetPage();
        unset($this->certificates);
        session()->flash('status', 'Constancia eliminada correctamente.');
    }
};
?>

<div>
    <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-300">Documentos de homologación</p>
            <h2 class="mt-3 text-3xl font-semibold tracking-tight">Constancias de cumplimiento NIV</h2>
            <p class="mt-3 text-slate-500 dark:text-slate-400">Composición y ubicación del NIV, datos del vehículo, vigencia y fe de errata.</p>
        </div>
        @if (auth()->user()->hasPermission(UserPermission::CreateNivComplianceCertificates))
            <a href="{{ route('niv_compliance_certificates.create') }}" wire:navigate class="shrink-0 rounded-xl bg-indigo-600 px-5 py-3 text-center font-semibold text-white transition hover:bg-indigo-500">Nueva constancia</a>
        @endif
    </div>
    @if (session('status'))
        <p role="status" class="mb-6 rounded-xl bg-emerald-50 p-4 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
    @endif
    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 lg:flex-row lg:items-end">
            <div class="grow">
                <label for="compliance-search" class="mb-2 block text-sm font-semibold">Buscar constancias</label>
                <input id="compliance-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Número, marca, modelo, empresa o NIV..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60">
            </div>
            <div class="lg:w-48">
                <label for="compliance-category-filter" class="mb-2 block text-sm font-semibold">Categoría</label>
                <select id="compliance-category-filter" wire:model.live="categoryFilter" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/60">
                    <option value="all">Todos</option><option value="nacional">Nacional</option><option value="importados">Importados</option>
                </select>
            </div>
            <div class="lg:w-64">
                <label for="compliance-product-filter" class="mb-2 block text-sm font-semibold">Producto relacionado</label>
                <select id="compliance-product-filter" wire:model.live="productFilter" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-slate-950/60">
                    <option value="">Todos los productos</option>
                    @if ($productFilter !== '' && ! $this->filterProducts->contains('id', (int) $productFilter))
                        <option value="{{ $productFilter }}">Producto no disponible</option>
                    @endif
                    @foreach ($this->filterProducts as $product)
                        <option wire:key="compliance-filter-product-{{ $product->id }}" value="{{ $product->id }}">{{ $product->name }} · {{ ucfirst($product->brand) }}</option>
                    @endforeach
                </select>
            </div>
            <x-per-page-selector id="compliance-per-page" />
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40 dark:text-slate-400"><tr>
                    @foreach (['Constancia', 'Categoría', 'Marca', 'Modelo', 'Serie / versión', 'Emisión', 'Vigente hasta', 'Acciones'] as $heading)
                        <th class="px-5 py-4 font-semibold">{{ $heading }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->certificates as $certificate)
                        <tr wire:key="compliance-{{ $certificate->id }}" class="transition hover:bg-indigo-50/60 dark:hover:bg-indigo-500/5">
                            <td class="px-5 py-4"><a href="{{ route('niv_compliance_certificates.edit', $certificate) }}" wire:navigate class="font-semibold text-indigo-600 dark:text-indigo-300">{{ $certificate->certificate_number ?: '#'.$certificate->id }}</a>@if ($certificate->has_erratum)<span class="mt-1 block text-xs text-amber-700 dark:text-amber-300">Con fe de errata</span>@endif</td>
                            <td class="px-5 py-4">{{ $certificate->category === 'importados' ? 'Importados' : 'Nacional' }}</td>
                            <td class="px-5 py-4">{{ $certificate->brand }}</td><td class="px-5 py-4">{{ $certificate->model }}</td><td class="px-5 py-4">{{ $certificate->series_version ?: 'Sin registrar' }}</td>
                            <td class="whitespace-nowrap px-5 py-4">{{ $certificate->issued_on?->format('d/m/Y') ?? 'Sin registrar' }}</td><td class="whitespace-nowrap px-5 py-4">{{ $certificate->expires_on?->format('d/m/Y') ?? 'Sin registrar' }}</td>
                            <td class="whitespace-nowrap px-5 py-4">
                                <a href="{{ route('niv_compliance_certificates.edit', $certificate) }}" wire:navigate class="mr-3 font-semibold text-indigo-600 dark:text-indigo-300">Ver</a>
                                <a href="{{ route('niv_compliance_certificates.download', $certificate) }}" class="font-semibold text-slate-600 dark:text-slate-300">PDF</a>
                                @if (auth()->user()->hasPermission(UserPermission::DeleteNivComplianceCertificates))<button wire:click="confirmDeletion({{ $certificate->id }})" type="button" class="ml-3 font-semibold text-rose-600 dark:text-rose-300">Eliminar</button>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-14 text-center text-slate-500 dark:text-slate-400">No se encontraron constancias. Carga el primer PDF para comenzar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->certificates->hasPages())<div class="border-t border-slate-200 p-5 dark:border-white/10">{{ $this->certificates->links() }}</div>@endif
    </section>
    @if ($deletingCertificateId !== null)
        <div class="fixed inset-0 z-50 grid place-items-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-labelledby="delete-compliance-title">
            <div class="w-full max-w-lg rounded-3xl bg-white p-7 shadow-xl dark:bg-slate-900">
                <h3 id="delete-compliance-title" class="text-xl font-semibold">¿Eliminar esta constancia?</h3>
                <p class="mt-3 text-slate-500 dark:text-slate-400">Se eliminarán el registro y su PDF original. Esta acción no se puede deshacer.</p>
                <div class="mt-6 flex justify-end gap-3"><button wire:click="cancelDeletion" type="button" class="rounded-xl border border-slate-200 px-5 py-3 dark:border-white/10">Cancelar</button><button wire:click="deleteCertificate" wire:loading.attr="disabled" type="button" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white disabled:opacity-50">Sí, eliminar</button></div>
            </div>
        </div>
    @endif
</div>
