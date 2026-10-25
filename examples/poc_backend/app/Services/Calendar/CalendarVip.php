<?php

namespace App\Services\Calendar;

use App\Models\User;
use App\Services\MembershipService;
use App\Support\Settings;

/**
 * "高級 VIP" for private calendar entries = the member's current M02 level code is
 * one of the codes chosen by the App admin (M09-D22; never a hard-coded level).
 */
class CalendarVip
{
    public function __construct(
        private readonly Settings $settings,
        private readonly MembershipService $memberships,
    ) {}

    /**
     * @return list<string>
     */
    public function levelCodes(): array
    {
        return Settings::csv($this->settings->string('calendar_vip_level_codes'));
    }

    public function isVip(User $user): bool
    {
        $codes = $this->levelCodes();
        if ($codes === [] || ! $user->is_active) {
            return false;
        }

        return in_array($this->memberships->current($user)['level']->code, $codes, true);
    }
}
