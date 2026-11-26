<?php

declare(strict_types=1);

namespace PpicShowcase\Wages;

/**
 * Weekly tailor wages — three steps, money only moves after the tailor signs.
 *
 *   1. ISSUED     finance issues the statement; quantities and the advance
 *                 deduction are locked
 *   2. CONFIRMED  the tailor checks the amount and signs on screen
 *   3. PAID       finance pays and attaches the transfer proof
 *
 * Wage = pieces that PASSED QC and are not yet paid × the rate snapshot taken
 * when the work order was issued (later rate changes never touch old work).
 * Pieces sent back for rework are frozen until they pass. Any outstanding
 * cash advance is deducted automatically, never below zero.
 *
 * Rewritten for this showcase (not the production code).
 */
final class WorkLine
{
    public function __construct(
        public readonly string $workOrder,
        public readonly int $passedUnpaid,
        public readonly int $inRework,
        public readonly int $rateSnapshot,   // rupiah per piece, frozen at work-order time
    ) {
        if ($passedUnpaid < 0 || $inRework < 0 || $rateSnapshot < 0) {
            throw new \InvalidArgumentException('Negative values are not allowed');
        }
    }
}

final class WageStatement
{
    public const ISSUED = 'ISSUED';
    public const CONFIRMED = 'CONFIRMED';
    public const PAID = 'PAID';

    private string $status = self::ISSUED;
    private ?string $signature = null;
    private ?string $transferProof = null;

    /** @param list<WorkLine> $lines */
    private function __construct(
        public readonly string $tailorCode,
        public readonly array $lines,
        public readonly int $gross,
        public readonly int $frozenForRework,
        public readonly int $advanceDeducted,
        public readonly int $net,
    ) {
    }

    /** @param list<WorkLine> $lines */
    public static function issue(string $tailorCode, array $lines, int $advanceBalance): self
    {
        $gross = 0;
        $frozen = 0;
        foreach ($lines as $line) {
            $gross += $line->passedUnpaid * $line->rateSnapshot;
            $frozen += $line->inRework * $line->rateSnapshot;
        }
        $deduct = min(max(0, $advanceBalance), $gross);

        return new self($tailorCode, $lines, $gross, $frozen, $deduct, $gross - $deduct);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function confirm(string $signatureImageId): void
    {
        if ($this->status !== self::ISSUED) {
            throw new \LogicException('Only an issued statement can be confirmed');
        }
        if ($signatureImageId === '') {
            throw new \InvalidArgumentException('A signature is required');
        }
        $this->signature = $signatureImageId;
        $this->status = self::CONFIRMED;
    }

    public function pay(string $transferProofId): void
    {
        if ($this->status !== self::CONFIRMED) {
            throw new \LogicException('Payment refused: the tailor has not confirmed and signed yet');
        }
        if ($transferProofId === '') {
            throw new \InvalidArgumentException('Transfer proof is required');
        }
        $this->transferProof = $transferProofId;
        $this->status = self::PAID;
    }
}
