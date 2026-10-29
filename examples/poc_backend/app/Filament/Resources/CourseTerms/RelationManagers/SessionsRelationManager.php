<?php

namespace App\Filament\Resources\CourseTerms\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\CourseSession;
use App\Models\CourseTerm;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = '課堂列表';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('課堂 ID')->sortable(),
            TextColumn::make('event.title')->label('課堂名稱')->searchable(),
            TextColumn::make('event.status')->label('活動狀態')->formatStateUsing(fn (?string $state): string => match ($state) {
                'draft' => '草稿',
                'published' => '已發布',
                'cancelled' => '已取消',
                default => $state ?? '—',
            }),
            TextColumn::make('event.starts_at')->label('開始時間')->dateTime('Y-m-d H:i', timezone: CalendarEventResource::displayTimezone())->sortable(),
            TextColumn::make('event.ends_at')->label('結束時間')->dateTime('Y-m-d H:i', timezone: CalendarEventResource::displayTimezone())->sortable(),
            TextColumn::make('makeup_capacity')->label('補課名額')->sortable(),
        ])->headerActions([
            Action::make('addSession')->label('新增課堂（選擇活動）')
                ->form([
                    Select::make('calendar_event_id')->label('尚未加入課程的活動')->required()->searchable()
                        ->options(fn (): array => CalendarEvent::query()
                            ->whereNotIn('id', DB::table('course_sessions')->select('calendar_event_id'))
                            ->where('status', '!=', CalendarEvent::STATUS_CANCELLED)
                            ->orderBy('starts_at')->limit(200)
                            ->get()->mapWithKeys(fn (CalendarEvent $event): array => [
                                $event->id => $event->title.' — '.$event->starts_at->timezone(CalendarEventResource::displayTimezone())->format('Y-m-d H:i'),
                            ])->all()),
                    TextInput::make('makeup_capacity')->label('補課名額')->integer()->minValue(0)->default(1)->required(),
                ])
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data): void {
                        $term = CourseTerm::query()->whereKey($this->getOwnerRecord()->getKey())->lockForUpdate()->firstOrFail();
                        if ($term->enrollments()->whereIn('status', ['pending_payment', 'confirmed'])->exists()) {
                            throw ValidationException::withMessages(['calendar_event_id' => '已有正式報名，禁止直接新增課堂；請先依整期預約調整流程處理。']);
                        }
                        $event = CalendarEvent::query()->whereKey($data['calendar_event_id'])->lockForUpdate()->first();
                        if ($event === null || $event->status === CalendarEvent::STATUS_CANCELLED
                            || CourseSession::query()->where('calendar_event_id', $data['calendar_event_id'])->exists()) {
                            throw ValidationException::withMessages(['calendar_event_id' => '活動不存在、已取消或已被其他課程使用。']);
                        }
                        CourseSession::query()->create([
                            'course_term_id' => $term->id,
                            'calendar_event_id' => $event->id,
                            'makeup_capacity' => (int) $data['makeup_capacity'],
                        ]);
                    }, 3);
                }),
        ])->recordActions([
            Action::make('editEvent')->label('編輯課堂活動')
                ->url(fn ($record): string => CalendarEventResource::getUrl('edit', ['record' => $record->calendar_event_id])),
        ]);
    }
}
