<?php

namespace App\Services;

/**
 * Immutable value object returned by PremiumCalculator::calculate().
 *
 * All numeric values in $breakdown are stored as plain strings so that
 * downstream code can reconstruct exact BigDecimal instances when needed,
 * without ever touching floats.
 */
final class PremiumResult
{
    /**
     * @param string $premiumAmount  Calculated premium, 2 dp, e.g. "225.00"
     * @param array  $breakdown {
     *     plan_code              : string,
     *     base_daily_rate        : string,
     *     travel_days            : int,
     *     age_at_trip_start      : int,
     *     age_multiplier         : string,
     *     destination            : string,
     *     destination_multiplier : string,
     *     sum_insured            : int,
     *     coverage_multiplier    : string,
     *     premium_amount         : string,
     * }
     */
    public function __construct(
        public readonly string $premiumAmount,
        public readonly array  $breakdown,
    ) {}
}
