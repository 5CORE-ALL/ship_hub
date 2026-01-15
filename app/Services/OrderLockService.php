<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Service for atomic order locking to prevent race conditions
 * 
 * This service provides thread-safe order locking using database-level
 * atomic operations to prevent multiple processes from processing the same order.
 */
class OrderLockService
{
    /**
     * Atomically lock orders that are not already locked
     * 
     * This method uses a conditional UPDATE to atomically lock orders,
     * preventing race conditions where multiple requests try to lock the same orders.
     * 
     * @param array $orderIds Array of order IDs to lock
     * @param int $timeoutMinutes Maximum time a lock should be considered valid (default: 5 minutes)
     * @return array ['locked_ids' => array, 'failed_ids' => array, 'stuck_ids' => array]
     */
    public function lockOrders(array $orderIds, int $timeoutMinutes = 5): array
    {
        if (empty($orderIds)) {
            return [
                'locked_ids' => [],
                'failed_ids' => [],
                'stuck_ids' => []
            ];
        }

        $now = now();
        $timeoutThreshold = $now->copy()->subMinutes($timeoutMinutes);

        // Step 1: Unlock any stuck orders (locked for more than timeout)
        $stuckOrders = Order::whereIn('id', $orderIds)
            ->where('queue', 1)
            ->where(function($query) use ($timeoutThreshold) {
                $query->where('queue_started_at', '<', $timeoutThreshold)
                      ->orWhereNull('queue_started_at');
            })
            ->pluck('id')
            ->toArray();

        if (!empty($stuckOrders)) {
            Log::warning('Found stuck orders, unlocking them', [
                'stuck_order_ids' => $stuckOrders,
                'timeout_minutes' => $timeoutMinutes
            ]);
            
            Order::whereIn('id', $stuckOrders)
                ->update([
                    'queue' => 0,
                    'queue_started_at' => null
                ]);
        }

        // Step 2: Atomically lock orders that are not already locked
        // This is a single atomic operation that prevents race conditions
        $lockedCount = Order::whereIn('id', $orderIds)
            ->where('queue', 0)
            ->update([
                'queue' => 1,
                'queue_started_at' => $now
            ]);

        // Step 3: Get the IDs of orders that were actually locked
        // Use a small time window to account for database timestamp precision
        $lockedIds = Order::whereIn('id', $orderIds)
            ->where('queue', 1)
            ->where('queue_started_at', '>=', $now->copy()->subSeconds(2))
            ->where('queue_started_at', '<=', $now->copy()->addSeconds(2))
            ->pluck('id')
            ->toArray();

        // Step 4: Identify orders that couldn't be locked (already locked by another process)
        $failedIds = array_diff($orderIds, $lockedIds);

        if (!empty($failedIds)) {
            Log::info('Some orders could not be locked (already being processed)', [
                'failed_order_ids' => $failedIds,
                'total_requested' => count($orderIds),
                'successfully_locked' => count($lockedIds)
            ]);
        }

        return [
            'locked_ids' => $lockedIds,
            'failed_ids' => $failedIds,
            'stuck_ids' => $stuckOrders
        ];
    }

    /**
     * Unlock orders
     * 
     * @param array $orderIds Array of order IDs to unlock
     * @return int Number of orders unlocked
     */
    public function unlockOrders(array $orderIds): int
    {
        if (empty($orderIds)) {
            return 0;
        }

        $unlockedCount = Order::whereIn('id', $orderIds)
            ->where('queue', 1)
            ->update([
                'queue' => 0,
                'queue_started_at' => null
            ]);

        if ($unlockedCount > 0) {
            Log::info('Orders unlocked', [
                'unlocked_count' => $unlockedCount,
                'order_ids' => $orderIds
            ]);
        }

        return $unlockedCount;
    }

    /**
     * Check if orders are locked
     * 
     * @param array $orderIds Array of order IDs to check
     * @return array ['locked' => array, 'unlocked' => array]
     */
    public function checkLockStatus(array $orderIds): array
    {
        if (empty($orderIds)) {
            return ['locked' => [], 'unlocked' => []];
        }

        $lockedIds = Order::whereIn('id', $orderIds)
            ->where('queue', 1)
            ->pluck('id')
            ->toArray();

        $unlockedIds = array_diff($orderIds, $lockedIds);

        return [
            'locked' => $lockedIds,
            'unlocked' => $unlockedIds
        ];
    }

    /**
     * Unlock stuck orders (locked for more than specified minutes)
     * 
     * @param int $timeoutMinutes Maximum time a lock should be considered valid
     * @return int Number of orders unlocked
     */
    public function unlockStuckOrders(int $timeoutMinutes = 10): int
    {
        $timeoutThreshold = now()->subMinutes($timeoutMinutes);

        $unlockedCount = Order::where('queue', 1)
            ->where(function($query) use ($timeoutThreshold) {
                $query->where('queue_started_at', '<', $timeoutThreshold)
                      ->orWhereNull('queue_started_at');
            })
            ->update([
                'queue' => 0,
                'queue_started_at' => null
            ]);

        if ($unlockedCount > 0) {
            Log::warning('Unlocked stuck orders', [
                'unlocked_count' => $unlockedCount,
                'timeout_minutes' => $timeoutMinutes
            ]);
        }

        return $unlockedCount;
    }
}
