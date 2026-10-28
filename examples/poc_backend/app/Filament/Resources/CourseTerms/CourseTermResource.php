<?php

namespace App\Filament\Resources\CourseTerms;

use App\Filament\Resources\CourseTerms\Pages\CreateCourseTerm;
use App\Filament\Resources\CourseTerms\Pages\EditCourseTerm;
use App\Filament\Resources\CourseTerms\Pages\ListCourseTerms;
use App\Models\CourseTerm;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CourseTermResource extends Resource
{
    protected static ?string $model = CourseTerm::class;
    protected static ?string $modelLabel = '課程期別';
    protected static ?string $pluralModelLabel = '課程管理';
    protected static ?string $slug = 'course-terms';
    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('events.manage') ?? false;
    }

    public static function canCreate(): bool { return static::canViewAny(); }
    public static function canEdit(Model $record): bool { return static::canViewAny(); }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('課程名稱')->required()->maxLength(255),
            TextInput::make('location')->label('地點')->maxLength(255),
            TextInput::make('floor_landmark')->label('樓層／地標')->maxLength(255),
            TextInput::make('studio')->label('教室')->maxLength(255),
            TextInput::make('enrollment_capacity')->label('正式名額')->integer()->minValue(1)->required(),
            TextInput::make('waitlist_capacity')->label('候補名額')->integer()->minValue(0)->default(5)->required(),
            TextInput::make('cancel_cutoff_days')->label('取消截止（開課前天數）')->integer()->minValue(0)->default(10)->required(),
            TextInput::make('price_amount')->label('整期課程金額（NT$）')->numeric()->minValue(1)->step(1)->default(1)->required(),
            TextInput::make('payment_hold_hours')->label('付款保留時數（空白採全域預設）')->integer()->minValue(1)->nullable(),
            DateTimePicker::make('booking_closes_at')->label('報名截止時間')->seconds(false)->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('title')->label('課程名稱')->searchable(),
            TextColumn::make('price_amount')->label('整期金額')->sortable(),
            TextColumn::make('enrollment_capacity')->label('正式名額'),
            TextColumn::make('waitlist_capacity')->label('候補名額'),
            TextColumn::make('booking_closes_at')->label('報名截止')->dateTime('Y-m-d H:i'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseTerms::route('/'),
            'create' => CreateCourseTerm::route('/create'),
            'edit' => EditCourseTerm::route('/{record}/edit'),
        ];
    }
}
