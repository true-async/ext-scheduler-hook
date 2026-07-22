# ext-scheduler-hook

Write an async scheduler in plain PHP, on top of the concurrency capabilities of the PHP engine.

This extension is a **bridge**. It adds no concurrency of its own: it exposes the engine's
scheduler contract to PHP so that a scheduler can be implemented, inspected and tested in userland
rather than in C.

## Status

Experimental, and deliberately so.

It exists for testing, verification and experimentation. A production scheduler is written in C
and does not need this extension at all. The engine-side proposal that makes it possible is
[PHP RFC: Concurrency Support in the PHP Engine](https://github.com/true-async/php-async-core-rfc/blob/main/scheduler_rfc.md),
and this bridge is explicitly **not** part of that proposal: it is one possible implementation of
an activation surface, shipped separately for exactly that reason.

## Requirements

A PHP build with the engine concurrency capabilities: the
[`async-core`](https://github.com/true-async/php-src/tree/async-core) branch of php-src. It does
not build against upstream PHP.

## Building

```sh
git clone https://github.com/true-async/ext-scheduler-hook
cd /path/to/php-src
cp -r /path/to/ext-scheduler-hook ext/async_scheduler_hook

./buildconf --force
./configure --enable-async-scheduler-hook
make -j$(nproc)
```

Run the extension's own suite:

```sh
make test TESTS=ext/async_scheduler_hook/tests
```

## What it exposes

Four symbols in the `Async\` namespace. Their storage and semantics belong to the engine; this
extension only provides the surface.

| Symbol | Purpose |
|---|---|
| `Async\SchedulerHook` | activation point: `register()`, `getModule()`, `defer()` |
| `Async\Scheduler` | the interface a scheduler implements |
| `Async\Context` | per-coroutine key-value storage |
| `Async\get_context()` | reaches the context of a coroutine, or of the running flow |

### Activation

A scheduler is registered once per process by handing `register()` a factory. The factory receives
three capabilities and returns the scheduler, already constructed with them:

```php
Async\SchedulerHook::register('my-scheduler',
    fn (callable $bindEntry, callable $switchTo, callable $currentCoroutine)
        => new MyScheduler($bindEntry, $switchTo, $currentCoroutine));
```

- `bindEntry(object $coroutine, callable $entry): void` gives one of the scheduler's own coroutine
  objects its body.
- `switchTo(object $coroutine, mixed $value = null, ?Throwable $error = null): mixed` transfers
  control into a coroutine symmetrically. The main coroutine, an adopted fiber and the scheduler's
  own coroutines all switch through this one path.
- `currentCoroutine(): ?object` reads the coroutine the engine records as running.

These are closures over engine internals that exist in no function table. Only the factory
receives them, so only the scheduler holds them.

### The scheduler interface

```php
interface Scheduler
{
    public function onLaunch(): object;
    public function onShutdown(): void;
    public function onFiber(\Fiber $fiber): ?object;
    public function onEnqueue(object $coroutine, ?\Throwable $error = null): bool;
    public function onSuspend(bool $fromMain, bool $isBailout): ?object;
    public function onDefer(callable $task): void;
}
```

The engine raises these; the scheduler decides what runs next. The engine never chooses a
coroutine on its own: it learns the current one only from the return value of `onLaunch()` and
`onSuspend()`.

A coroutine is the scheduler's **own object**, of any class. The engine never inspects it and only
passes it back.

## A minimal scheduler

`tests/mini_scheduler.inc` contains a complete working scheduler in about a hundred lines: FIFO
order, no event loop, no cancellation policy, but every notification handled. It is the same
`MiniScheduler` the RFC uses as its worked example, and it is the shortest way to see the whole
contract at once.

One rule from it is worth repeating, because omitting it is the classic bug of a distributed loop.
Control returning from `switchTo()` has two meanings: either this flow was itself scheduled, so
its turn came and it should stop scheduling, or the coroutine it switched into finished or parked,
so it should keep draining. The scheduler needs a marker to tell the two apart. Without one, a
flow frozen in the middle of its own scheduling pass is skipped over and never resumed.

## Per-coroutine context

`Async\get_context()` returns the store of the running flow, `get_context($coroutine)` that of a
given coroutine. Keys are strings or objects, the store is created on first access and dies with
its coroutine.

```php
Async\get_context()->set('request-id', $id);
$id = Async\get_context()->find('request-id');
```

A fresh coroutine starts empty. Whether a child sees anything of its parent is inheritance policy
and belongs to the scheduler, next to its own `spawn()`.

## Tests

22 `.phpt` files covering registration, the switch contract including value and error transfer,
fiber adoption, main-coroutine replacement, and the context.

## License

PHP License, same as php-src.
