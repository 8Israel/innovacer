<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The open sessions of a user, one per device or browser (e.g. the counter PC and
 * the phone's PWA), read from and closed in the database session store.
 * Closing a session logs that device out on its next request.
 */
class UserSessionService
{
    /**
     * The user's sessions, most recently used first.
     *
     * @return Collection<int, array{id: string, ip_address: string|null, browser: string, platform: string, last_active_at: Carbon, is_current: bool}>
     */
    public function sessionsFor(User $user, ?string $currentSessionId = null): Collection
    {
        return DB::table(config('session.table'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'browser' => $this->browser((string) $session->user_agent),
                'platform' => $this->platform((string) $session->user_agent),
                'last_active_at' => Carbon::createFromTimestamp($session->last_activity),
                'is_current' => $session->id === $currentSessionId,
            ]);
    }

    /**
     * Close one of the user's sessions. Sessions of other users are never touched.
     */
    public function close(User $user, string $sessionId): bool
    {
        return $this->sessions($user)->where('id', $sessionId)->delete() > 0;
    }

    /**
     * Close every session of the user except the given one (usually the current device).
     */
    public function closeOthers(User $user, string $keepSessionId): int
    {
        return $this->sessions($user)->where('id', '!=', $keepSessionId)->delete();
    }

    /**
     * Close every session of the user, e.g. when the user is deactivated.
     */
    public function closeAll(User $user): int
    {
        return $this->sessions($user)->delete();
    }

    private function sessions(User $user): Builder
    {
        return DB::table(config('session.table'))->where('user_id', $user->id);
    }

    private function browser(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => __('Navegador desconocido'),
        };
    }

    private function platform(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iPhone / iPad',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'Mac',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => __('Sistema desconocido'),
        };
    }
}
