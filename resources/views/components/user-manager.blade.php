<?php

use App\Models\User;
use App\UserPermission;
use App\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public $perPage = 10;

    public bool $showForm = false;

    #[Locked]
    public bool $formOnly = false;

    #[Locked]
    public bool $formReadOnly = false;

    public bool $showDeleteConfirmation = false;

    #[Locked]
    public ?int $editingUserId = null;

    #[Locked]
    public ?int $deletingUserId = null;

    #[Locked]
    public string $deletingUserName = '';

    #[Locked]
    public string $deletingUserEmail = '';

    public string $name = '';

    public string $email = '';

    public string $role = 'user';

    /** @var array<int, string> */
    public array $permissions = [];

    public string $password = '';

    public string $password_confirmation = '';

    public bool $passwordNeverExpires = true;

    public bool $passwordHasExpiration = false;

    public ?int $passwordExpirationDays = null;

    #[Locked]
    public ?string $passwordChangedAt = null;

    public ?string $status = null;

    public function mount(bool $formOnly = false, ?int $userId = null): void
    {
        $this->authorizePermission(UserPermission::ViewUsers);
        $this->formOnly = $formOnly;
        $this->status = session('status');

        if (! $formOnly) {
            return;
        }

        if ($userId === null) {
            $this->authorizePermission(UserPermission::CreateUsers);
            $this->showForm = true;

            return;
        }

        $user = User::query()->findOrFail($userId);
        $this->loadUser($user);
        $this->formReadOnly = ! $this->canEditUser($user);
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
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'permissions',
                'password_never_expires',
                'password_expiration_days',
                'password_changed_at',
                'created_at',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate((int) $this->perPage);
    }

    /**
     * @return array<int, UserRole>
     */
    public function roles(): array
    {
        return UserRole::cases();
    }

    /**
     * @return array<int, UserPermission>
     */
    public function availablePermissions(): array
    {
        return UserPermission::cases();
    }

    public function openCreateForm(): void
    {
        $this->authorizePermission(UserPermission::CreateUsers);
        $this->resetForm();
        $this->showForm = true;
    }

    public function editUser(int $userId): void
    {
        $this->authorizePermission(UserPermission::EditUsers);

        $user = User::query()->findOrFail($userId);
        $this->authorizeTargetUser($user);

        $this->loadUser($user);
    }

    public function updatedPasswordNeverExpires(bool $value): void
    {
        if ($value) {
            $this->passwordHasExpiration = false;
            $this->passwordExpirationDays = null;

            return;
        }

        if (! $this->passwordHasExpiration) {
            $this->passwordNeverExpires = true;
        }
    }

    public function updatedPasswordHasExpiration(bool $value): void
    {
        if ($value) {
            $this->passwordNeverExpires = false;

            return;
        }

        if (! $this->passwordNeverExpires) {
            $this->passwordHasExpiration = true;
        }
    }

    public function closeForm(): void
    {
        $this->resetForm();
    }

    public function openDeleteConfirmation(int $userId): void
    {
        $this->authorizePermission(UserPermission::DeleteUsers);

        $user = User::query()->findOrFail($userId);

        abort_if($user->is(auth()->user()), 403, 'No puedes eliminar tu propia cuenta.');
        $this->authorizeTargetUser($user);

        $this->deletingUserId = $user->id;
        $this->deletingUserName = $user->name;
        $this->deletingUserEmail = $user->email;
        $this->showDeleteConfirmation = true;
    }

    public function closeDeleteConfirmation(): void
    {
        $this->reset([
            'showDeleteConfirmation',
            'deletingUserId',
            'deletingUserName',
            'deletingUserEmail',
        ]);
    }

    public function deleteUser(): void
    {
        $this->authorizePermission(UserPermission::DeleteUsers);

        $user = User::query()->findOrFail($this->deletingUserId);

        abort_if($user->is(auth()->user()), 403, 'No puedes eliminar tu propia cuenta.');
        $this->authorizeTargetUser($user);

        $user->delete();

        if ($this->formOnly) {
            session()->flash('status', 'Usuario eliminado correctamente.');
            $this->redirectRoute('users.index', navigate: true);

            return;
        }

        $this->closeDeleteConfirmation();
        $this->resetPage();
        unset($this->users);
        $this->status = 'Usuario eliminado correctamente.';
    }

    public function save(): void
    {
        $requiredPermission = $this->editingUserId === null
            ? UserPermission::CreateUsers
            : UserPermission::EditUsers;
        $this->authorizePermission($requiredPermission);

        $emailRule = Rule::unique('users', 'email');

        if ($this->editingUserId !== null) {
            $emailRule->ignore($this->editingUserId);
        }

        if ($this->passwordNeverExpires === $this->passwordHasExpiration) {
            $this->addError('passwordExpiration', 'Selecciona una sola opción de vencimiento.');

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $emailRule],
            'role' => ['required', Rule::enum(UserRole::class)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::enum(UserPermission::class)],
            'passwordNeverExpires' => ['required', 'boolean'],
            'passwordHasExpiration' => ['required', 'boolean'],
            'passwordExpirationDays' => [
                Rule::requiredIf($this->passwordHasExpiration),
                'nullable',
                'integer',
                'min:1',
                'max:3650',
            ],
            'password' => [
                $this->editingUserId === null ? 'required' : 'nullable',
                'confirmed',
                Password::defaults(),
            ],
        ], [
            'name.required' => 'Ingresa el nombre del usuario.',
            'email.required' => 'Ingresa el correo electrónico.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.unique' => 'Ya existe un usuario con este correo.',
            'role.required' => 'Selecciona el rol del usuario.',
            'password.required' => 'Ingresa una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'passwordExpirationDays.required' => 'Indica la cantidad de días para el vencimiento.',
            'passwordExpirationDays.integer' => 'La cantidad de días debe ser un número entero.',
            'passwordExpirationDays.min' => 'La cantidad de días debe ser al menos 1.',
            'passwordExpirationDays.max' => 'La cantidad de días no puede superar 3650.',
        ]);

        $validated['password_never_expires'] = $validated['passwordNeverExpires'];
        $validated['password_expiration_days'] = $validated['passwordHasExpiration']
            ? $validated['passwordExpirationDays']
            : null;
        unset(
            $validated['passwordNeverExpires'],
            $validated['passwordHasExpiration'],
            $validated['passwordExpirationDays'],
        );

        if (
            in_array(UserPermission::ImportCertificates->value, $validated['permissions'], true)
            || in_array(UserPermission::DeleteCertificates->value, $validated['permissions'], true)
        ) {
            $validated['permissions'][] = UserPermission::ViewCertificates->value;
            $validated['permissions'] = array_values(array_unique($validated['permissions']));
        }

        if (array_intersect([
            UserPermission::CreateUsers->value,
            UserPermission::EditUsers->value,
            UserPermission::DeleteUsers->value,
        ], $validated['permissions']) !== []) {
            $validated['permissions'][] = UserPermission::ViewUsers->value;
            $validated['permissions'] = array_values(array_unique($validated['permissions']));
        }

        if (array_intersect([
            UserPermission::CreateProducts->value,
            UserPermission::EditProducts->value,
            UserPermission::DeleteProducts->value,
        ], $validated['permissions']) !== []) {
            $validated['permissions'][] = UserPermission::ViewProducts->value;
            $validated['permissions'] = array_values(array_unique($validated['permissions']));
        }

        if (array_intersect([
            UserPermission::CreateMotorcycleSerialRequests->value,
            UserPermission::EditMotorcycleSerialRequests->value,
            UserPermission::DeleteMotorcycleSerialRequests->value,
            UserPermission::DeleteCompletedMotorcycleSerialRequests->value,
        ], $validated['permissions']) !== []) {
            $validated['permissions'][] = UserPermission::ViewMotorcycleSerialRequests->value;
            $validated['permissions'] = array_values(array_unique($validated['permissions']));
        }

        if (in_array(UserPermission::EditVehicleIdentificationRecordManagement->value, $validated['permissions'], true)) {
            $validated['permissions'][] = UserPermission::ViewVehicleIdentificationRecordManagement->value;
            $validated['permissions'] = array_values(array_unique($validated['permissions']));
        }

        if ($this->editingUserId !== null) {
            $user = User::query()->findOrFail($this->editingUserId);
            $this->authorizeTargetUser($user);

            if (! auth()->user()->isAdministrator()) {
                $validated['role'] = $user->role->value;
                $validated['permissions'] = $user->permissions;
            }

            if ($user->is(auth()->user()) && $validated['role'] !== UserRole::Admin->value) {
                $this->addError('role', 'No puedes quitarte tu propio rol de administrador.');

                return;
            }

            if ($validated['password'] === '') {
                unset($validated['password']);
            } else {
                $validated['password_changed_at'] = now();
            }

            if (
                ! $validated['password_never_expires']
                && $user->password_changed_at === null
                && ! array_key_exists('password_changed_at', $validated)
            ) {
                $validated['password_changed_at'] = now();
            }

            $user->update($validated);
            $message = 'Usuario actualizado correctamente.';
        } else {
            if (! auth()->user()->isAdministrator()) {
                $validated['role'] = UserRole::User->value;
                $validated['permissions'] = [];
            }

            $validated['password_changed_at'] = now();
            $user = User::create($validated);
            $message = 'Usuario creado correctamente.';
            $this->resetPage();
        }

        if ($this->formOnly) {
            session()->flash('status', $message);
            $this->redirectRoute('users.edit', ['user' => $user], navigate: true);

            return;
        }

        $this->resetForm();
        $this->status = $message;
    }

    private function resetForm(): void
    {
        $this->reset([
            'showForm',
            'editingUserId',
            'name',
            'email',
            'password',
            'password_confirmation',
            'passwordNeverExpires',
            'passwordHasExpiration',
            'passwordExpirationDays',
            'passwordChangedAt',
            'permissions',
            'status',
        ]);
        $this->role = UserRole::User->value;
        $this->passwordNeverExpires = true;
        $this->resetValidation();
    }

    private function loadUser(User $user): void
    {
        $this->resetValidation();
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role->value;
        $this->permissions = $user->permissions;
        $this->password = '';
        $this->password_confirmation = '';
        $this->passwordNeverExpires = $user->password_never_expires;
        $this->passwordHasExpiration = ! $user->password_never_expires;
        $this->passwordExpirationDays = $user->password_expiration_days;
        $this->passwordChangedAt = $user->password_changed_at?->format('d/m/Y H:i');
        if (! $this->formOnly) {
            $this->status = null;
        }
        $this->showForm = true;
    }

    public function canEditUser(User $user): bool
    {
        $authenticatedUser = auth()->user();

        return $authenticatedUser?->hasPermission(UserPermission::EditUsers) === true
            && ($authenticatedUser->isAdministrator() || ! $user->isAdministrator());
    }

    public function canDeleteUser(User $user): bool
    {
        $authenticatedUser = auth()->user();

        return $authenticatedUser?->hasPermission(UserPermission::DeleteUsers) === true
            && ! $user->is($authenticatedUser)
            && ($authenticatedUser->isAdministrator() || ! $user->isAdministrator());
    }

    public function canDeleteEditingUser(): bool
    {
        if ($this->editingUserId === null) {
            return false;
        }

        $user = User::query()->find($this->editingUserId);

        return $user !== null && $this->canDeleteUser($user);
    }

    private function authorizePermission(UserPermission $permission): void
    {
        abort_unless(auth()->user()?->hasPermission($permission), 403);
    }

    private function authorizeTargetUser(User $user): void
    {
        abort_if(
            ! auth()->user()?->isAdministrator() && $user->isAdministrator(),
            403,
            'No tienes autorización para administrar cuentas de administradores.',
        );
    }
};
?>

<div>
    @unless ($formOnly)
    <div class="mb-8 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Administración</p>
            <h2 class="mt-3 text-3xl font-semibold tracking-tight">Usuarios</h2>
            <p class="mt-3 max-w-2xl leading-7 text-slate-600 dark:text-slate-400">
                Consulta, busca y administra las cuentas con acceso al sistema.
            </p>
        </div>

        @if (auth()->user()->hasPermission(UserPermission::CreateUsers))
            <a href="{{ route('users.create') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-500 px-5 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-400 focus:outline-none focus:ring-4 focus:ring-indigo-500/30">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Crear usuario
            </a>
        @endif
    </div>

    @if ($status)
        <div role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
            {{ $status }}
        </div>
    @endif

    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full max-w-lg">
                <label for="user-search" class="sr-only">Buscar usuarios</label>
                <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m2.1-5.4a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z"/>
                </svg>
                <input
                    id="user-search"
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Buscar por nombre o correo..."
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-12 pr-4 text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white"
                >
                <span wire:loading wire:target="search" class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-medium text-indigo-500">
                    Buscando...
                </span>
                </div>
            </div>
            <x-per-page-selector id="users-per-page" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-3xl text-left">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-950/40 dark:text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Usuario</th>
                        <th class="px-6 py-4 font-semibold">Rol</th>
                        <th class="px-6 py-4 font-semibold">Contraseña</th>
                        <th class="px-6 py-4 font-semibold">Registro</th>
                        <th class="px-6 py-4 text-right font-semibold">Acción</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($this->users as $user)
                        <tr
                            wire:key="user-{{ $user->id }}"
                            x-on:click="window.location.href = '{{ route('users.edit', $user) }}'"
                            @class([
                                'transition hover:bg-indigo-50/60 dark:hover:bg-indigo-500/5',
                                'cursor-pointer',
                            ])
                        >
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
                                        {{ $user->initials() }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold">{{ $user->name }}</p>
                                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span @class([
                                    'inline-flex rounded-full px-3 py-1 text-xs font-semibold',
                                    'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' => $user->role === UserRole::Admin,
                                    'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300' => $user->role === UserRole::User,
                                ])>
                                    {{ $user->role->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-1">
                                    @if ($user->password_never_expires)
                                        <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Nunca expira</span>
                                    @else
                                        <span @class([
                                            'text-sm font-semibold',
                                            'text-rose-700 dark:text-rose-300' => $user->passwordHasExpired(),
                                            'text-amber-700 dark:text-amber-300' => ! $user->passwordHasExpired(),
                                        ])>
                                            {{ $user->passwordHasExpired() ? 'Vencida' : "Expira cada {$user->password_expiration_days} días" }}
                                        </span>
                                    @endif
                                    <span class="text-xs text-slate-500 dark:text-slate-400">
                                        Última actualización:
                                        {{ $user->password_changed_at?->format('d/m/Y H:i') ?? 'No registrada' }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-500 dark:text-slate-400">
                                {{ $user->created_at?->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="inline-flex items-center justify-end gap-1">
                                    @if ($this->canDeleteUser($user))
                                        <button wire:click.stop="openDeleteConfirmation({{ $user->id }})" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-100 dark:text-rose-300 dark:hover:bg-rose-500/10">
                                            Eliminar
                                        </button>
                                    @endif
                                    @if (! $this->canEditUser($user) && ! $this->canDeleteUser($user))
                                        <a href="{{ route('users.edit', $user) }}" wire:navigate x-on:click.stop class="rounded-lg px-3 py-2 text-sm text-slate-500 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5">Solo lectura · Ver</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-14 text-center">
                                <p class="font-semibold">No se encontraron usuarios</p>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Prueba con otro nombre o correo.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->users->hasPages())
            <div class="border-t border-slate-200 px-6 py-4 dark:border-white/10">
                {{ $this->users->links() }}
            </div>
        @endif
    </div>
    @endunless

    @if ($showForm)
        @if ($formOnly)
            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <a href="{{ route('users.index') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>
                        Volver a la lista
                    </a>
                    <span class="text-sm text-slate-500 dark:text-slate-400">{{ $editingUserId ? "Usuario #{$editingUserId}" : 'Nuevo usuario' }}</span>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    @if ($this->canDeleteEditingUser())
                        <button wire:click="openDeleteConfirmation({{ $editingUserId }})" type="button" class="rounded-xl border border-rose-200 bg-white px-5 py-3 font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:bg-transparent dark:text-rose-300 dark:hover:bg-rose-500/10">Eliminar</button>
                    @endif
                    @unless ($formReadOnly)
                        <button wire:click="save" wire:loading.attr="disabled" wire:target="save" type="button" class="rounded-xl bg-indigo-500 px-6 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-400 disabled:cursor-wait disabled:opacity-60">
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
        @endif

        <div @class(['fixed inset-0 z-50 overflow-y-auto' => ! $formOnly]) role="dialog" aria-labelledby="user-form-title">
            @unless ($formOnly)
                <button wire:click="closeForm" type="button" class="fixed inset-0 cursor-default bg-slate-950/65 backdrop-blur-sm" aria-label="Cerrar formulario"></button>
            @endunless

            <div @class(['relative', 'flex min-h-full items-center justify-center p-4' => ! $formOnly])>
                <form wire:submit="save" class="relative w-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.04] sm:p-8 {{ $formOnly ? '' : 'max-w-2xl shadow-2xl dark:bg-slate-900' }}">
                    @unless ($formOnly)
                    <div class="mb-8 flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">
                                {{ $editingUserId ? 'Edición' : 'Nuevo registro' }}
                            </p>
                            <h3 id="user-form-title" class="mt-2 text-2xl font-semibold">
                                {{ $editingUserId ? 'Modificar usuario' : 'Crear usuario' }}
                            </h3>
                        </div>
                        <a href="{{ route('users.index') }}" wire:navigate class="grid size-10 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 dark:hover:bg-white/5 dark:hover:text-white">
                            <span class="sr-only">Cerrar</span>
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                            </svg>
                        </a>
                    </div>
                    @endunless

                    @if ($formOnly)
                        <div class="mb-8 border-b border-slate-200 pb-6 dark:border-white/10">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Información del usuario</p>
                            <h3 id="user-form-title" class="mt-2 text-2xl font-semibold">{{ $editingUserId ? 'Cuenta registrada' : 'Nuevo usuario' }}</h3>
                        </div>
                    @endif

                    <fieldset @disabled($formReadOnly) class="grid gap-5 sm:grid-cols-2 disabled:opacity-75">
                        <div class="sm:col-span-2">
                            <label for="name" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Nombre completo</label>
                            <input id="name" wire:model="name" type="text" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                            @error('name') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="email" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Correo electrónico</label>
                            <input id="email" wire:model="email" type="email" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                            @error('email') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-slate-950/40 sm:col-span-2">
                            <legend class="px-2 text-sm font-semibold text-slate-800 dark:text-slate-100">Vencimiento de contraseña</legend>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900">
                                    <input wire:model.live="passwordNeverExpires" type="checkbox" class="size-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-slate-950">
                                    <span class="text-sm font-semibold">Nunca expira la contraseña</span>
                                </label>

                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-900">
                                    <input wire:model.live="passwordHasExpiration" type="checkbox" class="size-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-slate-950">
                                    <span class="text-sm font-semibold">Contraseña con expiración</span>
                                </label>
                            </div>

                            @if ($passwordHasExpiration)
                                <div class="mt-4 max-w-xs">
                                    <label for="password_expiration_days" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Días para vencer</label>
                                    <input id="password_expiration_days" wire:model="passwordExpirationDays" type="number" min="1" max="3650" step="1" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                                    @error('passwordExpirationDays') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                                </div>
                            @endif

                            @error('passwordExpiration') <p class="mt-3 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror

                            <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
                                Última actualización:
                                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $passwordChangedAt ?? ($editingUserId ? 'No registrada' : 'Se registrará al crear el usuario') }}</span>
                            </p>
                            <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">Esta fecha solo cambia cuando se establece una contraseña nueva.</p>
                        </fieldset>

                        @if (auth()->user()->isAdministrator())
                            <div class="sm:col-span-2">
                                <label for="role" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Rol</label>
                                <select id="role" wire:model="role" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                                    @foreach ($this->roles() as $availableRole)
                                        <option value="{{ $availableRole->value }}">{{ $availableRole->label() }}</option>
                                    @endforeach
                                </select>
                                @error('role') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                            </div>

                            <fieldset class="sm:col-span-2">
                            <legend class="text-sm font-medium text-slate-700 dark:text-slate-200">Acceso a menús y funciones</legend>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                Los administradores tienen acceso completo automáticamente.
                            </p>

                            <div class="mt-3 grid gap-3">
                                @foreach ($this->availablePermissions() as $permission)
                                    <label wire:key="permission-{{ $permission->value }}" class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 transition hover:border-indigo-300 dark:border-white/10 dark:bg-slate-950/40 dark:hover:border-indigo-400/60">
                                        <input
                                            wire:model="permissions"
                                            type="checkbox"
                                            value="{{ $permission->value }}"
                                            class="mt-0.5 size-5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-white/20 dark:bg-slate-900"
                                        >
                                        <span>
                                            <span class="block text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $permission->label() }}</span>
                                            @if (in_array($permission, [UserPermission::ImportCertificates, UserPermission::DeleteCertificates], true))
                                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Al habilitarlo también se concede acceso al maestro.</span>
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('permissions.*') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                            </fieldset>
                        @else
                            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800 dark:border-sky-400/20 dark:bg-sky-500/10 dark:text-sky-200 sm:col-span-2">
                                Puedes administrar los datos de cuentas normales. Solamente un administrador puede cambiar roles y permisos.
                            </div>
                        @endif

                        <div>
                            <label for="password" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">
                                Contraseña {{ $editingUserId ? '(opcional)' : '' }}
                            </label>
                            <input id="password" wire:model="password" type="password" autocomplete="new-password" @required($editingUserId === null) class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                            @error('password') <p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Confirmar contraseña</label>
                            <input id="password_confirmation" wire:model="password_confirmation" type="password" autocomplete="new-password" @required($editingUserId === null) class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-slate-950 outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-slate-950/60 dark:text-white">
                        </div>

                    </fieldset>

                    @unless ($formOnly)
                    <div class="mt-8 flex flex-col-reverse gap-3 border-t border-slate-200 pt-6 sm:flex-row sm:justify-end dark:border-white/10">
                        <a href="{{ route('users.index') }}" wire:navigate class="rounded-xl border border-slate-200 px-5 py-3 text-center font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5">{{ $formReadOnly ? 'Volver' : 'Cancelar' }}</a>
                        @unless ($formReadOnly)
                        <button type="submit" class="rounded-xl bg-indigo-500 px-6 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-400 disabled:cursor-wait disabled:opacity-60" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ $editingUserId ? 'Guardar cambios' : 'Crear usuario' }}</span>
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
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="delete-user-title">
            <button wire:click="closeDeleteConfirmation" type="button" class="fixed inset-0 cursor-default bg-slate-950/70 backdrop-blur-sm" aria-label="Cancelar eliminación"></button>

            <div class="relative flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-6 shadow-2xl dark:border-rose-500/30 dark:bg-slate-900 sm:p-8">
                    <div class="grid size-14 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 4.5 2.7 18a2 2 0 0 0 1.74 3h15.12a2 2 0 0 0 1.74-3L13.7 4.5a2 2 0 0 0-3.4 0Z"/>
                        </svg>
                    </div>

                    <h3 id="delete-user-title" class="mt-5 text-2xl font-semibold">¿Eliminar este usuario?</h3>
                    <p class="mt-3 leading-7 text-slate-600 dark:text-slate-300">
                        Se eliminará permanentemente la cuenta de
                        <strong class="text-slate-950 dark:text-white">{{ $deletingUserName }}</strong>
                        ({{ $deletingUserEmail }}). Esta acción no se puede deshacer.
                    </p>

                    <div class="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button wire:click="closeDeleteConfirmation" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/15 dark:text-slate-200 dark:hover:bg-white/5">
                            Cancelar
                        </button>
                        <button wire:click="deleteUser" wire:loading.attr="disabled" wire:target="deleteUser" type="button" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60 dark:bg-rose-500 dark:hover:bg-rose-400">
                            <span wire:loading.remove wire:target="deleteUser">Sí, eliminar usuario</span>
                            <span wire:loading wire:target="deleteUser">Eliminando...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
