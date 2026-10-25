<?php

namespace App\Filament\Resources\VerificationRequests;

use App\Filament\Resources\VerificationRequests\Pages\EditVerificationRequest;
use App\Filament\Resources\VerificationRequests\Pages\ListVerificationRequests;
use App\Models\VerificationEvidence;
use App\Models\VerificationRequest;
use App\Models\VerificationReview;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * S9 M03 審核：authorised reviewers vote (D01, D06); evidence links are short-lived
 * signed admin URLs (D12). Reviewers never review their own application.
 */
class VerificationRequestResource extends Resource
{
    protected static ?string $model = VerificationRequest::class;

    protected static ?string $modelLabel = '認證申請';

    protected static ?string $pluralModelLabel = '認證審核';

    protected static ?string $slug = 'verification-requests';

    protected static ?int $navigationSort = 3;

    public const STATUS_LABELS = [
        VerificationRequest::PENDING => '審核中',
        VerificationRequest::APPROVED => '已通過',
        VerificationRequest::REJECTED => '【未通過】',
        VerificationRequest::WITHDRAWN => '【已撤回】',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('verifications.review') ?? false;
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

    public static function evidenceUrl(VerificationEvidence $evidence): string
    {
        return URL::temporarySignedRoute('admin.verification-evidence', now()->addMinutes(10), ['id' => $evidence->id]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('user.name')->label('會員'),
            TextEntry::make('type.name')->label('認證類型'),
            TextEntry::make('status')->label('狀態')->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),
            TextEntry::make('note')->label('會員說明')->placeholder('—'),
            TextEntry::make('progress')->label('審核進度')
                ->state(fn (VerificationRequest $record): string => $record->reviews()->where('decision', VerificationReview::APPROVE)->count().' / '.$record->type->required_approvals.' 位同意'),
            TextEntry::make('evidence_links')->label('證明文件（10 分鐘內有效）')->html()
                ->state(fn (VerificationRequest $record): string => $record->evidence->isEmpty() ? '—' : $record->evidence
                    ->map(fn (VerificationEvidence $e) => '<a href="'.e(static::evidenceUrl($e)).'" target="_blank" rel="noopener">'.e($e->original_name).'</a>')->implode('<br>')),
            TextEntry::make('reviews_list')->label('審核紀錄')
                ->state(fn (VerificationRequest $record): string => $record->reviews()->with('reviewer')->get()
                    ->map(fn (VerificationReview $r) => ($r->reviewer?->name ?? '—').'：'.($r->decision === 'approve' ? '同意' : '拒絕').($r->comment ? '（'.$r->comment.'）' : ''))->implode('；') ?: '—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone', 'UTC');

        return $table->modifyQueryUsing(fn ($query) => $query->with('user', 'type'))->columns([
            TextColumn::make('id')->label('#'),
            TextColumn::make('user.name')->label('會員')->searchable(),
            TextColumn::make('type.name')->label('類型'),
            TextColumn::make('status')->label('狀態')->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),
            TextColumn::make('created_at')->label('申請時間')->dateTime('Y-m-d H:i', $tz)->sortable(),
        ])->defaultSort('created_at', 'desc')
            ->filters([SelectFilter::make('status')->label('狀態')->options(self::STATUS_LABELS)->default(VerificationRequest::PENDING)])
            ->recordActions([EditAction::make()->label('審核')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerificationRequests::route('/'),
            'edit' => EditVerificationRequest::route('/{record}/edit'),
        ];
    }
}
