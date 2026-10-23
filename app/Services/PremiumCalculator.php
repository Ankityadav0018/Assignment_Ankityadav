<?php

namespace App\Services;

use App\Enums\Destination;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Calculates travel insurance premiums using exact decimal arithmetic
 * (brick/math BigDecimal) with zero floating-point operations.
 *
 * Formula (all factors are exact decimal strings):
 *   premium = base_daily_rate × travel_days × age_multiplier
 *             × destination_multiplier × coverage_multiplier
 *   Rounded to 2 decimal places, HALF_UP.
 */
final class PremiumCalculator
{
    // ── Constants ─────────────────────────────────────────────────────────────

    /** Base daily rate in currency units (integer strings, no floats). */
    private const DAILY_RATES = [
        'BASIC'   => '12',
        'PLUS'    => '20',
        'PREMIUM' => '30',
    ];

    /**
     * Age multipliers keyed by the *upper* boundary (inclusive) of each band.
     * The final entry (PHP_INT_MAX) covers age 71 and above.
     * Evaluated in ascending order; first band whose ceiling >= age wins.
     *
     *   0–17  → 1.10
     *  18–60  → 1.00
     *  61–70  → 1.50
     *  71+    → 2.00
     */
    private const AGE_BANDS = [
        17          => '1.10',
        60          => '1.00',
        70          => '1.50',
        PHP_INT_MAX => '2.00',
    ];

    /** Destination multipliers — keyed by Destination enum value (string). */
    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC'      => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    /** Coverage (sum insured) multipliers — keyed by integer amount. */
    private const COVERAGE_MULTIPLIERS = [
        100_000 => '1.00',
        300_000 => '1.25',
        500_000 => '1.50',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * @param  string      $planCode    One of BASIC | PLUS | PREMIUM.
     * @param  \DateTimeInterface $dateOfBirth Customer date of birth.
     * @param  \DateTimeInterface $tripStart   Inclusive trip start date.
     * @param  \DateTimeInterface $tripEnd     Inclusive trip end date (>= tripStart).
     * @param  Destination $destination DOMESTIC or INTERNATIONAL.
     * @param  int         $sumInsured  Must be 100_000, 300_000, or 500_000.
     *
     * @throws \DomainException On unsupported plan code or sum insured value.
     */
    public function calculate(
        string               $planCode,
        \DateTimeInterface   $dateOfBirth,
        \DateTimeInterface   $tripStart,
        \DateTimeInterface   $tripEnd,
        Destination          $destination,
        int                  $sumInsured,
    ): PremiumResult {
        // ── 1. Validate & resolve plan rate ──────────────────────────────────
        $planCode = strtoupper(trim($planCode));

        if (! array_key_exists($planCode, self::DAILY_RATES)) {
            throw new \DomainException(sprintf(
                'Unsupported plan code "%s". Supported plans: %s.',
                $planCode,
                implode(', ', array_keys(self::DAILY_RATES)),
            ));
        }

        $dailyRate = BigDecimal::of(self::DAILY_RATES[$planCode]);

        // ── 2. Validate & resolve coverage multiplier ─────────────────────────
        if (! array_key_exists($sumInsured, self::COVERAGE_MULTIPLIERS)) {
            throw new \DomainException(sprintf(
                'Unsupported sum insured %d. Supported values: %s.',
                $sumInsured,
                implode(', ', array_keys(self::COVERAGE_MULTIPLIERS)),
            ));
        }

        $coverageMultiplier = BigDecimal::of(self::COVERAGE_MULTIPLIERS[$sumInsured]);

        // ── 3. Travel days (inclusive, minimum 1) ─────────────────────────────
        $start    = CarbonImmutable::instance($tripStart)->startOfDay();
        $end      = CarbonImmutable::instance($tripEnd)->startOfDay();
        $travelDays = max(1, (int) $start->diffInDays($end) + 1);

        // ── 4. Age at trip start ───────────────────────────────────────────────
        $dob = CarbonImmutable::instance($dateOfBirth)->startOfDay();
        $ageAtTripStart = (int) $dob->diffInYears($start);

        $ageMultiplierValue = $this->resolveAgeMultiplier($ageAtTripStart);
        $ageMultiplier      = BigDecimal::of($ageMultiplierValue);

        // ── 5. Destination multiplier ─────────────────────────────────────────
        $destinationMultiplierValue = self::DESTINATION_MULTIPLIERS[$destination->value];
        $destinationMultiplier      = BigDecimal::of($destinationMultiplierValue);

        // ── 6. Premium calculation (no floats, exact arithmetic) ───────────────
        //
        //   premium = rate × days × age_mult × dest_mult × coverage_mult
        //
        $premium = $dailyRate
            ->multipliedBy($travelDays)
            ->multipliedBy($ageMultiplier)
            ->multipliedBy($destinationMultiplier)
            ->multipliedBy($coverageMultiplier)
            ->toScale(2, RoundingMode::HALF_UP);

        $premiumString = (string) $premium;

        // ── 7. Build immutable breakdown snapshot ─────────────────────────────
        $breakdown = [
            'plan_code'               => $planCode,
            'base_daily_rate'         => (string) $dailyRate,
            'travel_days'             => $travelDays,
            'age_at_trip_start'       => $ageAtTripStart,
            'age_multiplier'          => $ageMultiplierValue,
            'destination'             => $destination->value,
            'destination_multiplier'  => $destinationMultiplierValue,
            'sum_insured'             => $sumInsured,
            'coverage_multiplier'     => (string) $coverageMultiplier,
            'premium_amount'          => $premiumString,
        ];

        return new PremiumResult($premiumString, $breakdown);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns the age multiplier string for the given integer age.
     * Bands are evaluated in ascending ceiling order; the first ceiling that is
     * >= the age selects the band.
     */
    private function resolveAgeMultiplier(int $age): string
    {
        foreach (self::AGE_BANDS as $ceiling => $multiplier) {
            if ($age <= $ceiling) {
                return $multiplier;
            }
        }

        // Unreachable: PHP_INT_MAX ceiling guarantees a match.
        return '2.00'; // @codeCoverageIgnore
    }
}
