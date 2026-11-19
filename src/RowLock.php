<?php

namespace ErnestDefoe\Predictions;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * One writer per prediction (or per season) at a time.
 *
 * 🚨 `lockForUpdate()` alone is a no-op on SQLite, so every pick, settle, edit,
 * delete and scoring run also holds a cache lock named after the row. It never
 * waits: a request that finds it taken is told to try again (Refusal 409), not
 * left holding a PHP worker. Inside it, the work runs in a transaction and
 * re-reads its row with `lockForUpdate()`, which also serialises writers on
 * MySQL and PostgreSQL.
 */
class RowLock
{
    public const SECONDS = 15;

    public function __construct(
        private readonly Repository $cache,
        private readonly ConnectionInterface $db,
    ) {
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function run(string $name, Closure $work): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            // Every cache store Flarum ships (file, database, redis, array) can lock. Without one,
            // writes on SQLite would race, so refuse loudly instead of quietly running unlocked.
            throw new LogicException('Predictions needs a cache store that supports locks.');
        }

        $lock = $store->lock('ernestdefoe-predictions.'.$name, self::SECONDS);
        if ($lock->get() !== true) {
            throw new Refusal('predictions_busy', 409);
        }

        try {
            return $this->db->transaction($work);
        } finally {
            $lock->release();
        }
    }
}
