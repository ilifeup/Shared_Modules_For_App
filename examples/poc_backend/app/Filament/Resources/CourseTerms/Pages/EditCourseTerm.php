<?php

namespace App\Filament\Resources\CourseTerms\Pages;

use App\Filament\Resources\CourseTerms\CourseTermResource;
use App\Models\AuditEvent;
use App\Models\CourseTerm;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditCourseTerm extends EditRecord
{
    protected static string $resource = CourseTermResource::class;

    /**
     * Capacity is a term-wide seat authority. Lock the parent before counting
     * reservations, just as enrollment and waitlist admission do.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $term = CourseTerm::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $occupied = $term->enrollments()->whereIn('status', ['pending_payment', 'confirmed'])->count();
            $waiting = $term->enrollments()->where('status', 'waitlisted')->count();
            $capacity = (int) ($data['enrollment_capacity'] ?? $term->enrollment_capacity);
            $waitlistCapacity = (int) ($data['waitlist_capacity'] ?? $term->waitlist_capacity);

            if ($capacity < $occupied) {
                throw ValidationException::withMessages([
                    'data.enrollment_capacity' => '已有 '.$occupied.' 位正式報名者，請先依核准流程處理名額，不可直接縮減。',
                ]);
            }
            if ($waitlistCapacity < $waiting) {
                throw ValidationException::withMessages([
                    'data.waitlist_capacity' => '已有 '.$waiting.' 位候補者，請先依核准流程處理候補，不可直接縮減。',
                ]);
            }

            $before = [
                'enrollment_capacity' => (int) $term->enrollment_capacity,
                'waitlist_capacity' => (int) $term->waitlist_capacity,
            ];
            $term->fill($data)->save();

            if ($before['enrollment_capacity'] !== $capacity || $before['waitlist_capacity'] !== $waitlistCapacity) {
                AuditEvent::record('course.capacity_changed', auth()->id(), $term, [
                    'from' => $before,
                    'to' => ['enrollment_capacity' => $capacity, 'waitlist_capacity' => $waitlistCapacity],
                    'occupied' => $occupied,
                    'waiting' => $waiting,
                ]);
            }

            return $term;
        }, 3);
    }
}
