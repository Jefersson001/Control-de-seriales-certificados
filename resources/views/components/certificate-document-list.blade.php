<?php

use App\Models\CertificateDocument;
use App\UserPermission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public int $perPage = 10;

    public ?int $documentPendingDeletionId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::ViewCertificateDocuments), 403);
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

    public function openDeleteConfirmation(int $documentId): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteCertificateDocuments), 403);

        $this->documentPendingDeletionId = CertificateDocument::query()->findOrFail($documentId)->id;
    }

    public function closeDeleteConfirmation(): void
    {
        $this->documentPendingDeletionId = null;
    }

    public function deleteDocument(): void
    {
        abort_unless(auth()->user()?->hasPermission(UserPermission::DeleteCertificateDocuments), 403);
        abort_if($this->documentPendingDeletionId === null, 422);

        CertificateDocument::query()->findOrFail($this->documentPendingDeletionId)->delete();
        $this->documentPendingDeletionId = null;
        unset($this->documents);

        session()->flash('certificateDocumentDeletionResult', 'El certificado y su archivo PDF fueron eliminados correctamente.');
    }

    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return CertificateDocument::query()
            ->with('managements:id')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query
                    ->where('control_number', 'like', "%{$search}%")
                    ->orWhere('file_name', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate($this->perPage);
    }
};
?>

<div>
    @if (session('certificateDocumentDeletionResult'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
            {{ session('certificateDocumentDeletionResult') }}
        </div>
    @endif
    <div class="mb-8">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Consulta</p>
        <h2 class="mt-3 text-3xl font-semibold tracking-tight">Certificados</h2>
        <p class="mt-3 max-w-3xl leading-7 text-slate-600 dark:text-slate-400">
            Consulta y descarga los PDF guardados al confirmar su procesamiento.
        </p>
    </div>

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 sm:flex-row sm:items-end">
            <div class="w-full max-w-2xl">
                <label for="certificate-document-search" class="mb-2 block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    Buscar certificado
                </label>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/>
                        <path stroke-linecap="round" d="m20 20-4-4"/>
                    </svg>
                    <input
                        id="certificate-document-search"
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        placeholder="Escribe el número o nombre del certificado..."
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-12 pr-4 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white"
                    >
                </div>
            </div>
            <x-per-page-selector id="certificate-documents-per-page" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-3xl text-left">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40 dark:text-slate-400">
                    <tr>
                        <th class="px-5 py-4 font-semibold">Certificado</th>
                        <th class="px-5 py-4 font-semibold">Gestión</th>
                        <th class="px-5 py-4 font-semibold">Fecha</th>
                        <th class="px-5 py-4 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->documents as $document)
                        <tr wire:key="certificate-document-{{ $document->id }}" class="transition hover:bg-slate-50 dark:hover:bg-white/[0.025]">
                            <td class="whitespace-nowrap px-5 py-4 font-mono font-semibold text-indigo-600 dark:text-indigo-300">{{ $document->control_number }}</td>
                            <td class="whitespace-nowrap px-5 py-4">
                                @if ($document->imported_without_management)
                                    <span class="font-semibold text-slate-700 dark:text-slate-200">Carga completa</span>
                                @endif
                                @if ($document->managements->isNotEmpty())
                                    <div class="{{ $document->imported_without_management ? 'mt-1' : '' }} flex flex-wrap gap-1.5">
                                        @foreach ($document->managements as $management)
                                            <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">Gestión #{{ $management->id }}</span>
                                        @endforeach
                                    </div>
                                @elseif (! $document->imported_without_management)
                                    Sin relación
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 text-slate-600 dark:text-slate-300">{{ $document->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('certificate_documents.download', $document) }}" class="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-slate-950 bg-white px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-slate-950 hover:text-white dark:border-white dark:bg-slate-900 dark:text-white dark:hover:bg-white dark:hover:text-slate-950">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/>
                                    </svg>
                                    {{ $document->file_name }}
                                </a>
                                @if (auth()->user()->hasPermission(UserPermission::DeleteCertificateDocuments))
                                    <button wire:click="openDeleteConfirmation({{ $document->id }})" type="button" class="rounded-xl border border-rose-200 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:text-rose-300 dark:hover:bg-rose-500/10">Eliminar</button>
                                @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-14 text-center">
                                <p class="font-semibold">No se encontraron certificados</p>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">No existen archivos que coincidan con la búsqueda.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->documents->hasPages())
            <div class="border-t border-slate-200 px-6 py-4 dark:border-white/10">
                {{ $this->documents->links() }}
            </div>
        @endif
    </div>

    @if ($documentPendingDeletionId !== null)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-certificate-document-title">
            <div wire:click="closeDeleteConfirmation" class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm"></div>
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl bg-white p-7 text-center shadow-2xl dark:bg-slate-900">
                    <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.8 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.8a2 2 0 0 0-3.4 0Z"/></svg>
                    </div>
                    <h3 id="delete-certificate-document-title" class="mt-5 text-2xl font-semibold">¿Eliminar este certificado?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">Se eliminarán el registro y el archivo PDF guardado. Esta acción no se puede deshacer.</p>
                    <div class="mt-7 flex justify-center gap-3">
                        <button wire:click="closeDeleteConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold transition hover:bg-slate-50 dark:border-white/15 dark:hover:bg-white/5">Cancelar</button>
                        <button wire:click="deleteDocument" wire:loading.attr="disabled" wire:target="deleteDocument" type="button" class="rounded-xl bg-rose-600 px-7 py-3 font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"><span wire:loading.remove wire:target="deleteDocument">Sí, eliminar</span><span wire:loading wire:target="deleteDocument">Eliminando...</span></button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
