<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\UserPermission;
use App\UserRole;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property array<int, string> $permissions
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $password_never_expires
 * @property int|null $password_expiration_days
 * @property Carbon|null $password_changed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'permissions',
    'password_never_expires',
    'password_expiration_days',
    'password_changed_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => UserRole::User->value,
        'permissions' => '[]',
        'password_never_expires' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_never_expires' => 'boolean',
            'password_expiration_days' => 'integer',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'permissions' => 'array',
        ];
    }

    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** @return HasMany<MotorcycleSerialRequest, $this> */
    public function motorcycleSerialRequests(): HasMany
    {
        return $this->hasMany(MotorcycleSerialRequest::class);
    }

    public function hasPermission(UserPermission $permission): bool
    {
        return $this->isAdministrator()
            || in_array($permission->value, $this->permissions, true);
    }

    public function passwordExpiresAt(): ?CarbonInterface
    {
        if ($this->password_never_expires || $this->password_expiration_days === null) {
            return null;
        }

        $passwordChangedAt = $this->password_changed_at ?? $this->created_at;

        return $passwordChangedAt?->copy()->addDays($this->password_expiration_days);
    }

    public function passwordHasExpired(): bool
    {
        return $this->passwordExpiresAt()?->isPast() === true;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
