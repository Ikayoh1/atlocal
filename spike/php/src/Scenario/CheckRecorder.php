<?php

declare(strict_types=1);

namespace AtLocal\Spike\Scenario;

/**
 * Records spike checks. PASS/FAIL are assertions; FINDING records observed
 * behaviour that needs a decision (it is not hidden as a pass).
 */
final class CheckRecorder
{
    /** @var list<array{id: string, outcome: string, expectation: string, detail: string}> */
    private array $checks = [];

    public function pass(string $id, string $expectation, string $detail = ''): void
    {
        $this->add($id, 'PASS', $expectation, $detail);
    }

    public function fail(string $id, string $expectation, string $detail): void
    {
        $this->add($id, 'FAIL', $expectation, $detail);
    }

    public function finding(string $id, string $expectation, string $detail): void
    {
        $this->add($id, 'FINDING', $expectation, $detail);
    }

    public function assert(bool $condition, string $id, string $expectation, string $detail = ''): bool
    {
        $condition ? $this->pass($id, $expectation, $detail) : $this->fail($id, $expectation, $detail);
        return $condition;
    }

    /** Runs a check body; any uncaught exception is a FAIL, never a silent pass. */
    public function run(string $id, string $expectation, callable $body): void
    {
        try {
            $body($this);
        } catch (\Throwable $e) {
            $this->fail($id, $expectation, get_class($e) . ': ' . $e->getMessage());
        }
    }

    public function hasFailures(): bool
    {
        foreach ($this->checks as $c) {
            if ($c['outcome'] === 'FAIL') {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{id: string, outcome: string, expectation: string, detail: string}> */
    public function all(): array
    {
        return $this->checks;
    }

    private function add(string $id, string $outcome, string $expectation, string $detail): void
    {
        $this->checks[] = compact('id', 'outcome', 'expectation', 'detail');
        fwrite(STDOUT, sprintf("[%-7s] %-38s %s%s\n", $outcome, $id, $expectation, $detail !== '' ? "\n          -> {$detail}" : ''));
    }
}
