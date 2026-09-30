<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class ReportUser extends Model
{
    public const PERMISSION_STOCK_CATALOG_ALIASES_APPROVE = 'stock.catalog_aliases.approve';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_DIRECTOR = 'director';

    public const ROLE_AREA_MANAGER = 'area_manager';

    public const LEGACY_ROLE_AREA_MANAGER_OWN_AREA = 'area_manager_own_area';

    public const ROLE_VIEWER = 'viewer';

    public const ROLE_COMMISSION_AUDITOR = 'commission_auditor';

    public const ROLE_DELEGATION_MANAGER = 'delegation_manager';

    public const ROLE_MARKETING = 'marketing';

    public const ROLE_FINANCIAL = 'financial';

    public const ROLE_COMMERCIAL = 'commercial';

    public const ROLE_STOCK_ONLY = 'stock_only';

    public const ROLE_WEIGHTS = [
        self::ROLE_VIEWER => 10,
        self::ROLE_AREA_MANAGER => 20,
        self::ROLE_DELEGATION_MANAGER => 20,
        self::ROLE_MARKETING => 20,
        self::ROLE_FINANCIAL => 20,
        self::ROLE_COMMERCIAL => 10,
        self::ROLE_DIRECTOR => 30,
        self::ROLE_ADMIN => 40,
    ];

    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrador',
        self::ROLE_DIRECTOR => 'Direccion',
        self::ROLE_AREA_MANAGER => 'Area Manager',
        self::ROLE_VIEWER => 'Viewer',
        self::ROLE_COMMISSION_AUDITOR => 'Auditor de comisiones',
        self::ROLE_DELEGATION_MANAGER => 'Responsable de delegación',
        self::ROLE_MARKETING => 'Marketing',
        self::ROLE_FINANCIAL => 'Financiero',
        self::ROLE_COMMERCIAL => 'Comercial',
        self::ROLE_STOCK_ONLY => 'Stock exclusivamente',
    ];

    public const AREA_ZONE_LABELS = [
        'north' => 'Zona Norte',
        'catalonia' => 'Zona Cataluña',
        'mediterranean' => 'Zona Mediterraneo',
        'south_center' => 'Zona Sur y Centro',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'area_zone',
        'master_delegation_id',
        'salesforce_user_id',
        'is_active',
        'last_login_at',
        'password_changed_at',
        'permissions',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'permissions' => 'array',
    ];

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = str_starts_with($value, '$2y$') || str_starts_with($value, '$argon')
            ? $value
            : Hash::make($value);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isAdmin() || in_array($permission, $this->permissions ?? [], true);
    }

    public static function availablePermissions(): array
    {
        return [self::PERMISSION_STOCK_CATALOG_ALIASES_APPROVE];
    }

    public function isDirector(): bool
    {
        return $this->role === self::ROLE_DIRECTOR;
    }

    public function isAreaManager(): bool
    {
        return in_array($this->role, [self::ROLE_AREA_MANAGER, self::LEGACY_ROLE_AREA_MANAGER_OWN_AREA], true);
    }

    public function masterDelegation(): BelongsTo
    {
        return $this->belongsTo(MasterDelegation::class);
    }

    public static function availableRoles(): array
    {
        return array_keys(self::ROLE_LABELS);
    }

    public static function roleOptions(): array
    {
        return self::ROLE_LABELS;
    }

    public static function areaZoneOptions(): array
    {
        return self::AREA_ZONE_LABELS;
    }

    public static function areaZoneLabel(?string $zone): ?string
    {
        return self::AREA_ZONE_LABELS[$zone ?? ''] ?? null;
    }

    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[$role ?? ''] ?? (string) $role;
    }

    public static function roleWeight(?string $role): int
    {
        return self::ROLE_WEIGHTS[$role ?? ''] ?? 0;
    }
}
