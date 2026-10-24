<?php

namespace App\Filament\Resources\Notices;

use App\Filament\Resources\Notices\Pages\CreateNotice;
use App\Filament\Resources\Notices\Pages\EditNotice;
use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\Notice;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static ?string $modelLabel = '公告';

    protected static ?string $pluralModelLabel = '公告管理';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('標題')->required()->maxLength(255),
            Textarea::make('body')->label('內容')->required()->rows(10)->columnSpanFull(),
            Select::make('status')->label('狀態')
                ->options([
                    Notice::STATUS_DRAFT => '草稿（會員看不到）',
                    Notice::STATUS_PUBLISHED => '發布',
                ])
                ->default(Notice::STATUS_DRAFT)
                ->required(),
            DateTimePicker::make('published_at')->label('發布時間')
                ->helperText('留空並選「發布」時，以儲存當下為發布時間；設定未來時間則到時才對會員顯示。'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('標題')->searchable()->limit(40),
                TextColumn::make('status')->label('狀態')
                    ->formatStateUsing(fn (string $state): string => $state === Notice::STATUS_PUBLISHED ? '已發布' : '草稿'),
                TextColumn::make('published_at')->label('發布時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotices::route('/'),
            'create' => CreateNotice::route('/create'),
            'edit' => EditNotice::route('/{record}/edit'),
        ];
    }
}
