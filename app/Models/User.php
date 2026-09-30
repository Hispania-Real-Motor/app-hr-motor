<?php

namespace App\Models;

use App\Services\CompanyChatDefaultGroupSyncService;
use App\Notifications\UserPasswordResetNotification;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'gestor';

    public const ROLE_USER = 'usuario';

    public const ROLE_HR_NEWCARS = 'hr_newcars';

    public const ROLE_COMMERCIAL = 'comercial';

    public const ROLE_STORE_MANAGER = 'jefe_tienda';

    public const ROLE_INFORMATION_TECHNOLOGY = 'informatica';

    public const ROLE_MARKETING = 'marketing';

    public const ROLE_ADMINISTRATION = 'administracion';

    public const ROLE_AREA_MANAGER = 'area_manager';

    public const ROLE_LEGAL = 'legal';

    public const ROLE_GUARANTEES = 'garantias';

    public const ROLE_CALL_CENTER = 'call_center';

    public const ROLE_CAPTADOR = 'captador';

    public const ROLE_WORKSHOP = 'taller';

    public const ROLE_FINANCING = 'financiacion';

    public const ROLE_TRAINING = 'formacion';

    public const ROLE_MANAGEMENT = 'gerencia';

    public const ROLE_LOGISTICS = 'logistica';

    public const ROLE_HUMAN_RESOURCES = 'recursos_humanos';

    public const ROLE_SPARE_PARTS = 'recambios';

    public const ROLE_RENTING = 'renting';

    public const DEFAULT_AVATAR_PATH = 'images/users/hrmotor-default-user-avatar.png';

    public static function roleLabels(): array
    {
        return array_merge(self::baseRoleLabels(), self::extraRoleLabels());
    }

    public static function baseRoleLabels(): array
    {
        return [
            self::ROLE_ADMIN => 'Admin',
            self::ROLE_MANAGER => 'Gestor',
            self::ROLE_USER => 'Usuario',
        ];
    }

    public static function extraRoleLabels(): array
    {
        return [
            self::ROLE_COMMERCIAL => 'Comercial',
            self::ROLE_STORE_MANAGER => 'Jefe de tienda',
            self::ROLE_INFORMATION_TECHNOLOGY => 'Informática',
            self::ROLE_MARKETING => 'Marketing',
            self::ROLE_ADMINISTRATION => 'Administración',
            self::ROLE_AREA_MANAGER => 'Área Manager',
            self::ROLE_LEGAL => 'Legal',
            self::ROLE_GUARANTEES => 'Garantías',
            self::ROLE_CALL_CENTER => 'Call Center',
            self::ROLE_CAPTADOR => 'Captador',
            self::ROLE_WORKSHOP => 'Taller',
            self::ROLE_FINANCING => 'Financiación',
            self::ROLE_TRAINING => 'Formación',
            self::ROLE_MANAGEMENT => 'Gerencia',
            self::ROLE_LOGISTICS => 'Logística',
            self::ROLE_HUMAN_RESOURCES => 'Recursos Humanos',
            self::ROLE_SPARE_PARTS => 'Recambios',
            self::ROLE_RENTING => 'Renting',
            self::ROLE_HR_NEWCARS => 'HR NewCars',
        ];
    }

    public static function notificationTargetRolesFor(self $user): array
    {
        return $user->role === self::ROLE_ADMIN
            ? array_keys(self::roleLabels())
            : array_keys(self::extraRoleLabels());
    }

    public const DEALERSHIPS = [
        'Torrejón',
        'Manresa',
        'Pamplona',
        'Zaragoza',
        'Bilbao',
        'Sevilla',
        'Fontellas',
        'Valencia',
        'Alicante',
        'Villalba',
        'Sant Boi de Llobregat',
        'Rivas-Vaciamadrid',
        'Gijón',
        'Murcia',
        'Valladolid',
        'Alcalá de Guadaira',
        'Lleida',
        'San Sebastián',
        'Lliçà de Vall',
        'Alcobendas',
        'Badalona',
        'Girona',
        'Mallorca',
        'A Coruña',
        'Paterna',
        'Málaga',
        'Málaga Centro',
        'Castellón',
        'Sedaví',
        'Elche',
        'Alcoy',
        'Villarreal',
        'Dos Hermanas',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'extra_role',
        'salesforce_user_id',
        'company_entry_date',
        'job_position',
        'dealership',
        'dealership_id',
        'avatar_path',
        'linkedin_url',
        'phone',
        'enreach_extension',
        'it_monday_start',
        'it_monday_end',
        'it_tuesday_start',
        'it_tuesday_end',
        'it_wednesday_start',
        'it_wednesday_end',
        'it_thursday_start',
        'it_thursday_end',
        'it_friday_start',
        'it_friday_end',
        'password',
        'is_active',
        'must_change_password',
        'activated_at',
        'disabled_at',
        'disabled_by',
        'disabled_reason',
        'invitation_sent_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (blank($user->role)) {
                $user->role = self::ROLE_USER;
            }

            if (blank($user->avatar_path)) {
                $user->avatar_path = self::DEFAULT_AVATAR_PATH;
            }
        });

        static::created(function (self $user): void {
            app(CompanyChatDefaultGroupSyncService::class)->syncUser($user, false);
        });

        static::updated(function (self $user): void {
            if ($user->wasChanged(['is_active', 'disabled_at'])) {
                app(CompanyChatDefaultGroupSyncService::class)->syncUser($user, false);
                return;
            }

            if (! $user->wasChanged(['role', 'extra_role', 'dealership_id'])) {
                return;
            }

            app(CompanyChatDefaultGroupSyncService::class)->syncUser($user, true);
        });
    }

    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = $this->normalizeAgendaValue($value);
    }

    public function setEnreachExtensionAttribute($value): void
    {
        $this->attributes['enreach_extension'] = $this->normalizeAgendaValue($value);
    }

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
            'company_entry_date' => 'date',
            'activated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return app_user_can_access_admin_panel($this);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new UserPasswordResetNotification($token));
    }

    public function getAvatarUrlAttribute(): string
    {
        return asset($this->avatar_path ?: self::DEFAULT_AVATAR_PATH);
    }

    public function assignedDealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class, 'dealership_id');
    }

    public function chatFavoriteContacts(): HasMany
    {
        return $this->hasMany(CompanyChatFavoriteContact::class, 'user_id');
    }

    public function chatGroups(): BelongsToMany
    {
        return $this->belongsToMany(CompanyChatGroup::class, 'company_chat_group_user')
            ->withTimestamps();
    }

    public function adminPermissionGroups(): BelongsToMany
    {
        return $this->belongsToMany(AdminPermissionGroup::class, 'admin_permission_group_user')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function adminPermissionGrants(): HasMany
    {
        return $this->hasMany(AdminPermissionGrant::class);
    }

    public function policyAcceptances(): HasMany
    {
        return $this->hasMany(PolicyAcceptance::class);
    }

    public function disabledByUser(): BelongsTo
    {
        return $this->belongsTo(self::class, 'disabled_by');
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isPendingActivation(): bool
    {
        return ! $this->is_active && $this->disabled_at === null;
    }

    public function hasAcceptedPolicyVersion(string $policyVersion): bool
    {
        return $this->policyAcceptances()
            ->where('policy_version', $policyVersion)
            ->exists();
    }

    public function getResolvedDealershipNameAttribute(): ?string
    {
        return $this->assignedDealership?->name ?: $this->dealership;
    }

    public function isFavoriteChatContact(?self $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->chatFavoriteContacts()
            ->where('favorite_user_id', $user->id)
            ->exists();
    }

    public function isCommercialLike(): bool
    {
        return $this->role === self::ROLE_USER
            && in_array($this->extra_role, [
                self::ROLE_COMMERCIAL,
                self::ROLE_STORE_MANAGER,
                self::ROLE_AREA_MANAGER,
                self::ROLE_HR_NEWCARS,
            ], true);
    }

    public function isCommercialProfile(): bool
    {
        return $this->role === self::ROLE_USER && $this->extra_role === self::ROLE_COMMERCIAL;
    }

    public function isRankedCommercial(): bool
    {
        return $this->role === self::ROLE_USER
            && in_array($this->extra_role, [
                self::ROLE_COMMERCIAL,
                self::ROLE_STORE_MANAGER,
                self::ROLE_AREA_MANAGER,
                self::ROLE_HR_NEWCARS,
            ], true);
    }

    public function isStoreManager(): bool
    {
        return $this->extra_role === self::ROLE_STORE_MANAGER;
    }

    public function isInformationTechnology(): bool
    {
        return $this->extra_role === self::ROLE_INFORMATION_TECHNOLOGY;
    }

    public function getIsInformationTechnologyAttribute(): bool
    {
        return $this->isInformationTechnology();
    }

    public function getRoleLabelAttribute(): string
    {
        $baseLabel = self::roleLabels()[$this->role] ?? 'Usuario';
        $extraLabel = $this->extra_role ? (self::extraRoleLabels()[$this->extra_role] ?? ucfirst($this->extra_role)) : null;

        if (! $extraLabel) {
            return $baseLabel;
        }

        if ($this->role === self::ROLE_USER) {
            return $extraLabel;
        }

        return $baseLabel . ' · ' . $extraLabel;
    }

    public function getChatRoleLabelAttribute(): string
    {
        if (filled($this->extra_role)) {
            return self::extraRoleLabels()[$this->extra_role] ?? ucfirst((string) $this->extra_role);
        }

        return self::roleLabels()[$this->role] ?? 'Usuario';
    }

    public function getTenureBadgeAttribute(): ?array
    {
        if (! $this->company_entry_date) {
            return null;
        }

        $years = (int) $this->company_entry_date->diffInYears(now());

        if ($years < 1) {
            return null;
        }

        $tone = $years >= 5 ? 'legacy' : ($years >= 3 ? 'veteran' : 'starter');

        return [
            'years' => $years,
            'label' => '+'.$years.' '.($years === 1 ? 'año' : 'años'),
            'tone' => $tone,
        ];
    }

    public function getIsStoreManagerAttribute(): bool
    {
        return $this->isStoreManager();
    }

    public function getIsRankedCommercialAttribute(): bool
    {
        return $this->isRankedCommercial();
    }

    public function isInvitationExpired(): bool
    {
        if ($this->is_active || ! $this->must_change_password || $this->isDisabled()) {
            return false;
        }

        $sentAt = $this->invitation_sent_at ?? $this->created_at;

        if (! $sentAt) {
            return false;
        }

        $expiresInMinutes = (int) config('auth.passwords.users.expire', 60);

        return $sentAt->lt(now()->subMinutes($expiresInMinutes));
    }

    protected function normalizeAgendaValue(mixed $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
