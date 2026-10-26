<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ADMIN_ROLE = 'admin';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'display_name',
        'nickname',
        'show_in_like_list',
        'email',
        'phone_e164',
        'password',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'admin_identity_note',
        'attachment_upload_enabled',
        'attachment_upload_expires_at',
        'attachment_weekly_upload_count',
        'attachment_weekly_upload_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'show_in_like_list' => 'boolean',
            'password' => 'hashed',
            'attachment_upload_enabled' => 'boolean',
            'attachment_upload_expires_at' => 'datetime',
        ];
    }

    /**
     * Only the single App administrator (Spatie role "admin", ADR-004) may enter Filament.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasRole(self::ADMIN_ROLE);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ADMIN_ROLE);
    }

    /**
     * S8: Manual administrator entitlement. Membership tier or payment history
     * never implicitly enables uploads; member verification remains mandatory.
     */
    public function canUploadArticleAttachments(): bool
    {
        return $this->is_active
            && app(\App\Support\VerificationPolicy::class)->isSatisfiedBy($this)
            && $this->attachment_upload_enabled === true
            && ($this->attachment_upload_expires_at === null
                || $this->attachment_upload_expires_at->isFuture());
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * @return HasMany<MemberLevelAssignment, $this>
     */
    public function levelAssignments(): HasMany
    {
        return $this->hasMany(MemberLevelAssignment::class);
    }

    /**
     * S3 A03: required profile fields that are still missing (old members may lack some).
     *
     * @return list<string>
     */
    public function missingProfileFields(): array
    {
        return array_values(array_filter([
            blank($this->name) ? 'name' : null,
            blank($this->email) ? 'email' : null,
            blank($this->phone_e164) ? 'phone' : null,
        ]));
    }

    /**
     * Members without an email address never receive verification mail.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->email !== null) {
            parent::sendEmailVerificationNotification();
        }
    }
}
