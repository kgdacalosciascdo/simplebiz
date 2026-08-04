<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'preferred_company_id',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['status', 'is_owner', 'last_active_at'])
            ->withTimestamps();
    }

    public function hasPermission(string $permission, ?int $companyId): bool
    {
        if (! $companyId || $this->status !== 'active') {
            return false;
        }

        $membership = $this->companies()->whereKey($companyId)->wherePivot('status', 'active')->first();
        if (! $membership) {
            return false;
        }
        if ((bool) $membership->pivot->is_owner) {
            return true;
        }

        return $this->roles()
            ->where('roles.company_id', $companyId)
            ->wherePivot('company_id', $companyId)
            ->whereHas('permissions', fn ($query) => $query->where('permissions.key', $permission))
            ->exists();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('company_id')
            ->withTimestamps();
    }
}
