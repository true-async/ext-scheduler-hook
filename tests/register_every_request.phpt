--TEST--
register(): a later request of the same process binds its own scheduler again
--EXTENSIONS--
async_scheduler_hook
--SKIPIF--
<?php
if (Async\SchedulerHook::getModule() !== null) die('skip a C scheduler occupies the slot');
if (getenv('TEST_PHP_EXTRA_ARGS') === false) die('skip needs run-tests');
?>
--FILE--
<?php
$script = __DIR__ . '/register_every_request.inc';
file_put_contents($script, <<<'PHP'
<?php
var_dump(Async\SchedulerHook::getModule());
require __DIR__ . '/switch_harness.inc';
$s = register_switch_harness();
($s->switch)($s->coroutine(function (): void { echo "  in the coroutine\n"; }));
var_dump(Async\SchedulerHook::getModule());
PHP);

// --repeat runs the script twice in one process, with a request shutdown and
// startup in between.
echo shell_exec(escapeshellarg(PHP_BINARY) . ' ' . getenv('TEST_PHP_EXTRA_ARGS')
    . ' --repeat 2 ' . escapeshellarg($script) . ' 2>&1');
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/register_every_request.inc');
?>
--EXPECTF--
Executing for the first time...
NULL
  in the coroutine
string(14) "switch-harness"
Finished execution, repeating...
NULL
  in the coroutine
string(14) "switch-harness"
