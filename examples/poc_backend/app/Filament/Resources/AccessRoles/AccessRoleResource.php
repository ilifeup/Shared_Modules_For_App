<?php

namespace App\Filament\Resources\AccessRoles;

use App\Filament\Resources\AccessRoles\Pages\EditAccessRole;
use App\Filament\Resources\AccessRoles\Pages\ListAccessRoles;
use App\Models\AuditEvent;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S9 module roles granted separately (never automatic for every admin):
 * analyst (M17 reports, D23), checkin_staff (M11 staff in the App), verification_reviewer (M03).
 */
class AccessRoleResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = '模組權限';

    protected static ?string $pluralModelLabel = '模組權限';

    protected static ?string $slug = 'access-roles';

    protected static ?int $navigationSort = 8;

    public const ROLES = [
        'analyst' => '分析報表（M17 完整統計與匯出）',
        'checkin_staff' => '簽到工作人員（App 掃描與名單）',
        'verification_reviewer' => '認證審核員（M03）',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('roles.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('帳號'),
            CheckboxList::make('module_roles')->label('授予的模組權限')->options(self::ROLES)
                ->afterStateHydrated(fn (CheckboxList $component, ?User $record) => $component->state($record?->roles->pluck('name')->intersect(array_keys(self::ROLES))->values()->all() ?? []))
                ->dehydrated(false),
        ]);
    }

    public static function saveRoles(User $user, array $roles, User $actor): void
    {
        $roles = array_values(array_intersect($roles, array_keys(self::ROLES)));
        $before = $user->roles->pluck('name')->intersect(array_keys(self::ROLES))->values()->all();
        foreach (array_keys(self::ROLES) as $role) {
            in_array($role, $roles, true) ? $user->assignRole($role) : $user->removeRole($role);
        }
        AuditEvent::record('roles.module_updated', $actor->id, $user, ['before' => $before, 'after' => $roles]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('#'),
            TextColumn::make('name')->label('姓名')->searchable(),
            TextColumn::make('email')->label('Email')->searchable()->placeholder('—'),
            TextColumn::make('roles.name')->label('角色')->badge()->placeholder('—'),
        ])->recordActions([EditAction::make()->label('權限')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccessRoles::route('/'),
            'edit' => EditAccessRole::route('/{record}/edit'),
        ];
    }
}
