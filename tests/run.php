<?php

declare(strict_types=1);

// Tiny dependency-free test runner: `php tests/run.php`

require __DIR__ . '/../examples/Wages.php';
require __DIR__ . '/../examples/Planning.php';

use PpicShowcase\Planning\MaterialPlanner;
use PpicShowcase\Planning\TailorLoad;
use PpicShowcase\Wages\WageStatement;
use PpicShowcase\Wages\WorkLine;

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (\Throwable) {
        return true;
    }

    return false;
}

// --- wages (demo numbers) ---
$lines = [
    new WorkLine('WO-DEMO-01', passedUnpaid: 20, inRework: 2, rateSnapshot: 9000),
    new WorkLine('WO-DEMO-02', passedUnpaid: 10, inRework: 0, rateSnapshot: 12000),
];
$s = WageStatement::issue('T-001', $lines, advanceBalance: 50000);
check('gross = 20×9000 + 10×12000 = 300000', $s->gross === 300000);
check('rework pieces are frozen, not paid', $s->frozenForRework === 18000);
check('advance deducted automatically', $s->advanceDeducted === 50000 && $s->net === 250000);

$small = WageStatement::issue('T-002', [new WorkLine('WO-DEMO-03', 1, 0, 9000)], advanceBalance: 100000);
check('deduction never makes net negative', $small->advanceDeducted === 9000 && $small->net === 0);

check('cannot pay before the tailor signs', throws(fn () => $s->pay('proof-1')));
$s->confirm('signature-1');
check('signed statement is confirmed', $s->status() === WageStatement::CONFIRMED);
$s->pay('proof-1');
check('paid after confirmation', $s->status() === WageStatement::PAID);
check('cannot confirm twice', throws(fn () => $s->confirm('signature-2')));

// --- material requirement ---
$r = MaterialPlanner::requirement(
    ['FABRIC-M' => 0.45, 'ZIPPER-PC' => 2, 'WEBBING-M' => 1.2],
    poQuantity: 100,
    stock: ['FABRIC-M' => 60, 'ZIPPER-PC' => 150, 'WEBBING-M' => 200],
);
check('BOM × qty', $r['need'] === ['FABRIC-M' => 45.0, 'ZIPPER-PC' => 200.0, 'WEBBING-M' => 120.0]);
check('shortage drafted only where stock is short', $r['shortage'] === ['ZIPPER-PC' => 50.0]);
check('readiness 2 of 3 materials', abs($r['ready'] - 2 / 3) < 1e-9);
check('cutting capped at 110 %', MaterialPlanner::maxCutting(100) === 110 && MaterialPlanner::maxCutting(25) === 27);

// --- tailor load ---
$load = new TailorLoad(['easy' => 60, 'medium' => 40, 'hard' => 20]);
$orders = [
    ['difficulty' => 'easy', 'given' => 60, 'returned' => 30],   // 0.5
    ['difficulty' => 'hard', 'given' => 10, 'returned' => 0],    // 0.5
];
check('load = 30/60 + 10/20 = 100 %', $load->loadPercent($orders) === 100.0);
check('held at 100 %', $load->canTakeNewWork($orders) === false);
check('free below 100 %', $load->canTakeNewWork([['difficulty' => 'medium', 'given' => 20, 'returned' => 0]]));

echo PHP_EOL . ($count - $failures) . "/$count passed" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
