<?php
declare(strict_types=1);

namespace App\Shared;

// Shared account policy. All callers persist under the users row lock.
final class HeartRules
{
    public const MAX_HP = 3;
    public const REGEN_MINUTES = 30;

    public static function refresh(array $user, \DateTimeImmutable $now): array
    {
        $hp = min(self::MAX_HP, max(0, (int) $user['current_hp']));
        $next = empty($user['next_hp_at']) ? null
            : new \DateTimeImmutable($user['next_hp_at'], new \DateTimeZone('UTC'));
        if ($hp < self::MAX_HP && $next !== null && $next <= $now) {
            $steps = 1 + intdiv($now->getTimestamp() - $next->getTimestamp(), self::REGEN_MINUTES * 60);
            $hp = min(self::MAX_HP, $hp + $steps);
            $next = $next->modify('+' . ($steps * self::REGEN_MINUTES) . ' minutes');
        }
        if ($hp === self::MAX_HP) {
            $next = null;
        } elseif ($next === null) {
            $next = $now->modify('+' . self::REGEN_MINUTES . ' minutes');
        }
        return ['current_hp' => $hp, 'next_hp_at' => $next?->format('Y-m-d H:i:s')];
    }

    public static function lose(array $user, \DateTimeImmutable $now): array
    {
        $state = self::refresh($user, $now);
        $state['current_hp'] = max(0, $state['current_hp'] - 1);
        $state['next_hp_at'] ??= $now->modify('+' . self::REGEN_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        return $state;
    }
}
