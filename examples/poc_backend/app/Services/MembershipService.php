<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\MemberLevelAssignment;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M02 member levels. The current level is computed on every read, so an expired
 * VIP period falls back to the default level (一般) without a scheduled job, and
 * the expired assignment stays as history. Levels never touch Spatie roles.
 */
final class MembershipService
{
    /**
     * @return array{level: MembershipLevel, assignment: MemberLevelAssignment|null}
     */
    public function current(User $user, ?Carbon $at = null): array
    {
        $at ??= now();

        $assignment = MemberLevelAssignment::query()
            ->with('level')
            ->where('user_id', $user->id)
            ->activeAt($at)
            ->get()
            ->sortByDesc(fn (MemberLevelAssignment $a): array => [$a->level->rank, $a->starts_at->getTimestamp()])
            ->first();

        return [
            'level' => $assignment?->level ?? MembershipLevel::default(),
            'assignment' => $assignment,
        ];
    }

    /**
     * Assigns a non-default level for a period. An overlapping active assignment of
     * the same level is closed (revoked) so a member has one period per level.
     */
    public function assign(User $member, MembershipLevel $level, Carbon $startsAt, ?Carbon $endsAt, ?string $reason, User $actor): MemberLevelAssignment
    {
        if ($member->isAdmin()) {
            throw new ApiException(422, 'VALIDATION_FAILED', '管理員帳號不使用會員等級。');
        }

        if ($level->is_default) {
            throw new ApiException(422, 'VALIDATION_FAILED', '一般等級為預設等級，不需指派。', [
                'membership_level_id' => ['一般等級為預設等級，不需指派。'],
            ]);
        }

        if ($endsAt !== null && $endsAt->lte($startsAt)) {
            throw new ApiException(422, 'VALIDATION_FAILED', '結束時間必須晚於開始時間。', [
                'ends_at' => ['結束時間必須晚於開始時間。'],
            ]);
        }

        return DB::transaction(function () use ($member, $level, $startsAt, $endsAt, $reason, $actor): MemberLevelAssignment {
            // Serialize assignments for this member.
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            MemberLevelAssignment::query()
                ->where('user_id', $member->id)
                ->where('membership_level_id', $level->id)
                ->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt))
                ->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);

            $assignment = MemberLevelAssignment::query()->create([
                'user_id' => $member->id,
                'membership_level_id' => $level->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'assigned_by' => $actor->id,
            ]);

            AuditEvent::record('member.level_assigned', $actor->id, $member, [
                'level' => $level->code,
                'starts_at' => $startsAt->toIso8601ZuluString(),
                'ends_at' => $endsAt?->toIso8601ZuluString(),
            ]);

            return $assignment;
        });
    }

    public function revoke(MemberLevelAssignment $assignment, User $actor): void
    {
        if ($assignment->revoked_at !== null) {
            return;
        }

        $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();

        AuditEvent::record('member.level_revoked', $actor->id, $assignment->user, [
            'level' => $assignment->level->code,
            'assignment_id' => $assignment->id,
        ]);
    }
}
