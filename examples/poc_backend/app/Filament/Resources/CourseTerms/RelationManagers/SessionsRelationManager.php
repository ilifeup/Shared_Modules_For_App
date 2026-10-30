<?php

namespace App\Filament\Resources\CourseTerms\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\CourseSession;
use App\Models\CourseTerm;
use App\Services\Course\CourseMakeupAssignmentService;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
            TextColumn::make('makeups_count')->label('補課歷史筆數')->counts('makeups')->sortable(),
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
                    TextInput::make('makeup_capacity')->label('補課名額')->integer()->minValue(0)->default(fn (): int => app(Settings::class)->int('booking_makeup_default_capacity'))->required(),
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
            Action::make('assignMakeup')->label('指派補課')
                ->form([
                    Select::make('course_session_leave_id')->label('待安排請假')->required()->searchable()
                        ->options(fn (): array => DB::table('course_session_leaves as l')
                            ->join('course_enrollments as e', 'e.id', '=', 'l.course_enrollment_id')
                            ->join('users as u', 'u.id', '=', 'e.user_id')
                            ->where('e.status', 'confirmed')
                            ->whereNotIn('l.id', DB::table('course_makeups')->select('course_session_leave_id'))
                            ->orderBy('l.id')->limit(200)
                            ->get(['l.id', 'u.name'])
                            ->mapWithKeys(fn ($leave): array => [$leave->id => $leave->name.' — 請假 #'.$leave->id])->all()),
                ])
                ->action(function (CourseSession $record, array $data): void {
                    app(CourseMakeupAssignmentService::class)->assign(
                        (int) $data['course_session_leave_id'],
                        (int) $record->id,
                        Filament::auth()->user(),
                    );
                }),
            Action::make('adjustMakeupCapacity')->label('調整補課名額')
                ->form([
                    TextInput::make('makeup_capacity')->label('補課名額')
                        ->integer()->minValue(0)->required()
                        ->default(fn (CourseSession $record): int => (int) $record->makeup_capacity),
                ])
                ->action(function (CourseSession $record, array $data): void {
                    DB::transaction(function () use ($record, $data): void {
                        $term = CourseTerm::query()->whereKey($this->getOwnerRecord()->getKey())->lockForUpdate()->firstOrFail();
                        $session = CourseSession::query()
                            ->whereKey($record->id)->where('course_term_id', $term->id)
                            ->lockForUpdate()->firstOrFail();
                        // Cancelled makeup bookings remain as audit history but do
                        // not consume a live makeup seat after parent cancellation.
                        $assigned = DB::table('course_makeups as makeup')
                            ->join('event_booking_groups as booking', 'booking.id', '=', 'makeup.event_booking_group_id')
                            ->where('makeup.target_course_session_id', $session->id)
                            ->where('booking.status', 'confirmed')
                            ->count();
                        $capacity = (int) $data['makeup_capacity'];
                        if ($capacity < $assigned) {
                            throw ValidationException::withMessages([
                                'makeup_capacity' => '補課名額不得小於已指派的補課人數。',
                            ]);
                        }
                        $session->update(['makeup_capacity' => $capacity]);
                    }, 3);
                }),
            Action::make('removeSession')->label('移除課堂')->color('danger')
                ->requiresConfirmation()
                ->action(function (CourseSession $record): void {
                    DB::transaction(function () use ($record): void {
                        $term = CourseTerm::query()->whereKey($this->getOwnerRecord()->getKey())->lockForUpdate()->firstOrFail();
                        $session = CourseSession::query()
                            ->whereKey($record->id)->where('course_term_id', $term->id)
                            ->lockForUpdate()->firstOrFail();
                        if ($term->enrollments()->exists()
                            || DB::table('course_session_leaves')->where('course_session_id', $session->id)->exists()
                            || DB::table('course_makeups')->where('target_course_session_id', $session->id)->exists()) {
                            throw ValidationException::withMessages([
                                'calendar_event_id' => '已有報名、請假或補課紀錄，禁止移除課堂；請由管理員依核准流程處理。',
                            ]);
                        }
                        $session->delete();
                    }, 3);
                }),
            Action::make('editEvent')->label('編輯課堂活動')
                ->url(fn ($record): string => CalendarEventResource::getUrl('edit', ['record' => $record->calendar_event_id])),
        ]);
    }
}
