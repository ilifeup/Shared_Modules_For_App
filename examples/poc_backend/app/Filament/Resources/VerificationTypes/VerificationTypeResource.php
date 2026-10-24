<?php

namespace App\Filament\Resources\VerificationTypes;

use App\Filament\Resources\VerificationTypes\Pages\CreateVerificationType;
use App\Filament\Resources\VerificationTypes\Pages\EditVerificationType;
use App\Filament\Resources\VerificationTypes\Pages\ListVerificationTypes;
use App\Models\VerificationType;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S9 M03 verification types configured per App (D01, D02, D04, D06, D09, D10).
 */
class VerificationTypeResource extends Resource
{
    protected static ?string $model = VerificationType::class;

    protected static ?string $modelLabel = '認證類型';

    protected static ?string $pluralModelLabel = '認證類型';

    protected static ?string $slug = 'verification-types';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('verifications.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
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
            TextInput::make('name')->label('名稱')->required()->maxLength(80)->unique(ignoreRecord: true),
            Textarea::make('description')->label('說明（顯示給會員）')->rows(3)->maxLength(2000),
            Toggle::make('requires_evidence')->label('需要上傳證明文件'),
            TextInput::make('required_approvals')->label('需要幾位審核員同意')->numeric()->integer()->minValue(1)->maxValue(10)->default(1)->required(),
            TextInput::make('validity_days')->label('有效天數（空白＝永久有效）')->numeric()->integer()->minValue(1)->maxValue(36500),
            Toggle::make('allow_multiple')->label('允許同類多個有效認證')->default(true),
            Select::make('revoke_notify')->label('撤銷時通知')->options(['app' => 'App 站內通知', 'email' => 'Email', 'none' => '不通知'])->default('app')->required(),
            Toggle::make('is_active')->label('開放申請')->default(true),
            TextInput::make('sort')->label('排序')->numeric()->minValue(0)->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('名稱')->searchable(),
            TextColumn::make('required_approvals')->label('同意人數'),
            TextColumn::make('requires_evidence')->label('證明文件')->formatStateUsing(fn ($state): string => $state ? '需要' : '不需要'),
            TextColumn::make('validity_days')->label('有效期')->formatStateUsing(fn ($state): string => $state ? $state.' 天' : '永久')->placeholder('永久'),
            TextColumn::make('is_active')->label('狀態')->formatStateUsing(fn ($state): string => $state ? '開放' : '【停止申請】'),
        ])->defaultSort('sort')->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerificationTypes::route('/'),
            'create' => CreateVerificationType::route('/create'),
            'edit' => EditVerificationType::route('/{record}/edit'),
        ];
    }
}
