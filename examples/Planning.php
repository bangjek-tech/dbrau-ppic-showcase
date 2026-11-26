<?php

declare(strict_types=1);

namespace PpicShowcase\Planning;

/**
 * Two planning rules from the production-planning system, rewritten for this showcase.
 *
 * 1) Material requirement: when a purchase order (PO) arrives, multiply the
 *    bill of materials (BOM, per piece) by the PO quantity, compare with stock,
 *    and draft a purchase request for the shortage (the director approves it,
 *    fully or partly).
 *
 * 2) Tailor load: every tailor holds cut pieces that are not yet returned.
 *    Load = Σ (outstanding pieces ÷ weekly capacity for that article's
 *    difficulty). A tailor at 100 % or more is held back from new work orders.
 */
final class MaterialPlanner
{
    /**
     * @param array<string, float> $bomPerPiece  material code => usage per piece (in usage units)
     * @param array<string, float> $stock        material code => stock (in usage units)
     * @return array{need: array<string,float>, shortage: array<string,float>, ready: float}
     */
    public static function requirement(array $bomPerPiece, int $poQuantity, array $stock): array
    {
        if ($poQuantity <= 0) {
            throw new \InvalidArgumentException('PO quantity must be positive');
        }
        $need = [];
        $shortage = [];
        $covered = 0;
        foreach ($bomPerPiece as $material => $perPiece) {
            $need[$material] = round($perPiece * $poQuantity, 4);
            $have = $stock[$material] ?? 0.0;
            $missing = round(max(0.0, $need[$material] - $have), 4);
            if ($missing > 0) {
                $shortage[$material] = $missing;
            } else {
                $covered++;
            }
        }
        $ready = $bomPerPiece === [] ? 1.0 : $covered / count($bomPerPiece);

        return ['need' => $need, 'shortage' => $shortage, 'ready' => $ready];
    }

    /** Cutting may exceed the PO by at most 10 % (spare for rejects). */
    public static function maxCutting(int $poQuantity): int
    {
        return intdiv($poQuantity * 110, 100);
    }
}

final class TailorLoad
{
    /** @param array<string,int> $weeklyCapacity difficulty => pieces per week */
    public function __construct(private readonly array $weeklyCapacity)
    {
        foreach ($weeklyCapacity as $level => $pieces) {
            if ($pieces <= 0) {
                throw new \InvalidArgumentException("Capacity for $level must be positive");
            }
        }
    }

    /**
     * @param list<array{difficulty: string, given: int, returned: int}> $workOrders
     */
    public function loadPercent(array $workOrders): float
    {
        $load = 0.0;
        foreach ($workOrders as $wo) {
            $outstanding = max(0, $wo['given'] - $wo['returned']);
            $capacity = $this->weeklyCapacity[$wo['difficulty']]
                ?? throw new \InvalidArgumentException('Unknown difficulty ' . $wo['difficulty']);
            $load += $outstanding / $capacity;
        }

        return round($load * 100, 1);
    }

    /** @param list<array{difficulty: string, given: int, returned: int}> $workOrders */
    public function canTakeNewWork(array $workOrders): bool
    {
        return $this->loadPercent($workOrders) < 100.0;
    }
}
