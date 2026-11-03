---
paths:
  - 'tests/Feature/Concurrency/**'
---

# Concurrency

## Purge Redis connections before forking race workers
Guarded runs source .env, so CACHE_STORE=redis overrides phpunit.xml and DistributedLock uses real Redis. A forked worker that inherits the parent's open Redis socket reads other workers' replies, never sees its lock granted, and the lock stays held until its TTL (a 130s hang). Before pcntl_fork, call Redis::purge() for every connection in config('database.redis') (except client/options/clusters), alongside DB::purge().
