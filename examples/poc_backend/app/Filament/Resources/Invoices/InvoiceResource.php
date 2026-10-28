<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Services\Payment\CashInvoiceService;
use App\Services\Payment\InvoiceNumberAllocator;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;
    protected static ?string $modelLabel = '帳單';
    protected static ?string $pluralModelLabel = '帳單與發票';
    protected static ?string $slug = 'invoices';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('#')->sortable(),
            TextColumn::make('invoice_code')->label('帳單編號')->searchable(),
            TextColumn::make('invoice_amount')->label('金額'),
            TextColumn::make('invoice_status')->label('狀態')->formatStateUsing(fn ($state) => [
                0 => '未付', 1 => '已付', 2 => '取消', 3 => '逾期', 4 => '退款中', 5 => '已退款',
            ][$state] ?? (string) $state),
            TextColumn::make('invoice_no')->label('發票號碼')->placeholder('未配號'),
            TextColumn::make('created_at')->label('建立')->dateTime('Y-m-d H:i'),
        ])->defaultSort('id', 'desc')->recordActions([
            Action::make('confirmCash')->label('確認現金收款')
                ->visible(fn (Invoice $record) => (int) $record->invoice_status === 0)
                ->requiresConfirmation()
                ->action(function (Invoice $record): void {
                    app(CashInvoiceService::class)->confirmCash($record->id, auth()->user());
                    Notification::make()->title('已確認收款')->success()->send();
                }),
            Action::make('allocate')->label('自動配號')
                ->visible(fn (Invoice $record) => (int) $record->invoice_status === 1 && $record->invoice_no === null)
                ->action(function (Invoice $record): void {
                    app(InvoiceNumberAllocator::class)->allocate($record->id, auth()->user());
                    Notification::make()->title('發票已配號')->success()->send();
                }),
            Action::make('correct')->label('更正發票號碼')
                ->visible(fn (Invoice $record) => $record->invoice_no !== null)
                ->schema([
                    TextInput::make('invoice_no')->label('新發票號碼')->required()->maxLength(10)
                        ->default(fn (Invoice $record) => $record->invoice_no),
                    Textarea::make('reason')->label('原因（選填）')->maxLength(1000),
                ])
                ->action(function (Invoice $record, array $data): void {
                    app(InvoiceNumberAllocator::class)->correct($record->id, $data['invoice_no'], $data['reason'] ?? '', auth()->user());
                    Notification::make()->title('發票號碼已更正')->success()->send();
                }),
            Action::make('refund')->label('記錄現金退款')
                ->visible(fn (Invoice $record) => (int) $record->invoice_status === 1)
                ->schema([Textarea::make('reason')->label('退款原因')->required()->maxLength(2000)])
                ->requiresConfirmation()
                ->action(function (Invoice $record, array $data): void {
                    app(CashInvoiceService::class)->recordManualRefund($record->id, auth()->user(), $data['reason']);
                    Notification::make()->title('已記錄退款')->success()->send();
                }),
        ])->headerActions([
            Action::make('range')->label('設定發票號碼範圍')->schema([
                TextInput::make('start')->label('起號')->required(),
                TextInput::make('end')->label('迄號')->required(),
                TextInput::make('period')->label('期別 YYYYMM')->default(now()->format('Ym'))->required(),
            ])->action(function (array $data): void {
                app(InvoiceNumberAllocator::class)->addRange($data['start'], $data['end'], $data['period'], auth()->user());
                Notification::make()->title('已設定發票號碼範圍')->success()->send();
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
