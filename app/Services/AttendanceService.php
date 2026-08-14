<?php

namespace App\Services;

use App\Models\BusinessHour;
use App\Models\Conversation;
use App\Models\Setting;
use Carbon\Carbon;

class AttendanceService
{
    public function isAiGloballyEnabled(): bool
    {
        $stored = Setting::get('ai_enabled');

        if ($stored !== null) {
            return (bool) $stored;
        }

        return (bool) config('ai.enabled', true);
    }

    public function isHumanManuallyOnline(): bool
    {
        return (bool) Setting::get('human_online', false);
    }

    public function isWithinBusinessHours(?Carbon $moment = null): bool
    {
        $moment ??= now();
        $dayOfWeek = (int) $moment->dayOfWeek;

        $schedule = BusinessHour::query()
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if (! $schedule || $schedule->is_closed) {
            return false;
        }

        if (! $schedule->opens_at || ! $schedule->closes_at) {
            return false;
        }

        $opens = $moment->copy()->setTimeFromTimeString($schedule->opens_at);
        $closes = $moment->copy()->setTimeFromTimeString($schedule->closes_at);

        return $moment->betweenIncluded($opens, $closes);
    }

    public function isHumanAttendanceActive(): bool
    {
        if ($this->isHumanManuallyOnline()) {
            return true;
        }

        return $this->isWithinBusinessHours();
    }

    public function shouldSofiaRespond(Conversation $conversation): bool
    {
        if (! $this->isAiGloballyEnabled()) {
            return false;
        }

        if (in_array($conversation->status, [
            Conversation::STATUS_HUMAN,
            Conversation::STATUS_WAITING,
            Conversation::STATUS_CLOSED,
        ], true)) {
            return false;
        }

        if ($this->isHumanAttendanceActive()) {
            return false;
        }

        return $conversation->status === Conversation::STATUS_AI;
    }

    public function initialConversationStatus(): string
    {
        if ($this->isHumanAttendanceActive()) {
            return Conversation::STATUS_HUMAN;
        }

        return Conversation::STATUS_AI;
    }
}
