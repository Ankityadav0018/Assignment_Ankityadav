<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Services\PremiumCalculator;
use App\Services\PremiumResult;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PremiumCalculator.
 *
 * Formula:  premium = base_daily_rate × travel_days × age_mult × dest_mult × coverage_mult
 * Rounded to 2 dp, HALF_UP.  All arithmetic uses brick/math BigDecimal; no floats.
 *
 * Reference date used throughout: trip_start = 2024-06-01.
 * Ages are computed relative to that date using exact birthday arithmetic.
 */
class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    /** Fixed trip start used by most tests. */
    private const TRIP_START = '2024-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Returns a DateTimeImmutable for a date string.
     */
    private static function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    /**
     * Returns a date of birth string so that the person is exactly $age years
     * old on TRIP_START (their birthday falls on TRIP_START day).
     */
    private static function dobForExactAge(int $age): string
    {
        return CarbonImmutable::parse(self::TRIP_START)
            ->subYears($age)
            ->toDateString();
    }

    /**
     * Returns a date of birth one day AFTER the birthday that would make
     * someone $age years old — i.e., they are still ($age - 1) years old on
     * TRIP_START.
     */
    private static function dobOneDayBeforeAging(int $age): string
    {
        return CarbonImmutable::parse(self::TRIP_START)
            ->subYears($age)
            ->addDay()
            ->toDateString();
    }

    // ── Specified acceptance test ─────────────────────────────────────────────

    #[Test]
    public function plus_international_age28_300k_5days_equals_225(): void
    {
        // PLUS=20, days=5, age28→1.00, INTERNATIONAL→1.80, 300000→1.25
        // 20 × 5 × 1.00 × 1.80 × 1.25 = 225.00
        $tripStart = self::d(self::TRIP_START);
        $tripEnd   = self::d('2024-06-05'); // 5 inclusive days

        // Born on exactly the same date 28 years prior → age 28 on trip start
        $dob = self::d(self::dobForExactAge(28));

        $result = $this->calculator->calculate(
            planCode:    'PLUS',
            dateOfBirth: $dob,
            tripStart:   $tripStart,
            tripEnd:     $tripEnd,
            destination: Destination::International,
            sumInsured:  300_000,
        );

        $this->assertInstanceOf(PremiumResult::class, $result);
        $this->assertSame('225.00', $result->premiumAmount);

        // Verify breakdown values
        $b = $result->breakdown;
        $this->assertSame('PLUS',          $b['plan_code']);
        $this->assertSame('20',            $b['base_daily_rate']);
        $this->assertSame(5,               $b['travel_days']);
        $this->assertSame(28,              $b['age_at_trip_start']);
        $this->assertSame('1.00',          $b['age_multiplier']);
        $this->assertSame('INTERNATIONAL', $b['destination']);
        $this->assertSame('1.80',          $b['destination_multiplier']);
        $this->assertSame(300_000,         $b['sum_insured']);
        $this->assertSame('1.25',          $b['coverage_multiplier']);
        $this->assertSame('225.00',        $b['premium_amount']);
    }

    // ── Age boundary tests ────────────────────────────────────────────────────
    //
    // Isolation: BASIC (rate=12), DOMESTIC (×1.00), 100_000 (×1.00), 1 day.
    // Premium = 12 × 1 × age_mult × 1.00 × 1.00 = 12 × age_mult
    //
    //   age 17  → ×1.10 → 13.20
    //   age 18  → ×1.00 → 12.00
    //   age 60  → ×1.00 → 12.00
    //   age 61  → ×1.50 → 18.00
    //   age 70  → ×1.50 → 18.00
    //   age 71  → ×2.00 → 24.00

    /**
     * @param string $dob         Date of birth string.
     * @param int    $expectedAge Resolved age on TRIP_START.
     * @param string $expectedMult Age multiplier string.
     * @param string $expectedPremium Expected premium for BASIC/DOMESTIC/100k/1-day.
     */
    #[Test]
    #[DataProvider('ageBoundaryProvider')]
    public function age_boundaries_apply_correct_multiplier(
        string $dob,
        int    $expectedAge,
        string $expectedMult,
        string $expectedPremium,
    ): void {
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d($dob),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START), // same day = 1 day
            destination: Destination::Domestic,
            sumInsured:  100_000,
        );

        $this->assertSame($expectedAge,    $result->breakdown['age_at_trip_start'], 'age');
        $this->assertSame($expectedMult,   $result->breakdown['age_multiplier'],    'multiplier');
        $this->assertSame($expectedPremium, $result->premiumAmount,                 'premium');
    }

    public static function ageBoundaryProvider(): array
    {
        // Helper: "one day before aging to X" means still X-1 years old.
        $tripStart = self::TRIP_START;

        return [
            // ── Band 0–17 (×1.10) ────────────────────────────────────────────
            'upper edge of 0-17 band: exactly age 17' => [
                'dob'             => self::dobForExactAge(17),
                'expectedAge'     => 17,
                'expectedMult'    => '1.10',
                'expectedPremium' => '13.20',  // 12 × 1.10
            ],
            'still in 0-17 band: one day before 18th birthday' => [
                'dob'             => self::dobOneDayBeforeAging(18), // not yet 18
                'expectedAge'     => 17,
                'expectedMult'    => '1.10',
                'expectedPremium' => '13.20',
            ],

            // ── Band 18–60 (×1.00) ───────────────────────────────────────────
            'lower edge of 18-60 band: exactly age 18' => [
                'dob'             => self::dobForExactAge(18),
                'expectedAge'     => 18,
                'expectedMult'    => '1.00',
                'expectedPremium' => '12.00',  // 12 × 1.00
            ],
            'upper edge of 18-60 band: exactly age 60' => [
                'dob'             => self::dobForExactAge(60),
                'expectedAge'     => 60,
                'expectedMult'    => '1.00',
                'expectedPremium' => '12.00',
            ],
            'still in 18-60 band: one day before 61st birthday' => [
                'dob'             => self::dobOneDayBeforeAging(61), // not yet 61
                'expectedAge'     => 60,
                'expectedMult'    => '1.00',
                'expectedPremium' => '12.00',
            ],

            // ── Band 61–70 (×1.50) ───────────────────────────────────────────
            'lower edge of 61-70 band: exactly age 61' => [
                'dob'             => self::dobForExactAge(61),
                'expectedAge'     => 61,
                'expectedMult'    => '1.50',
                'expectedPremium' => '18.00',  // 12 × 1.50
            ],
            'upper edge of 61-70 band: exactly age 70' => [
                'dob'             => self::dobForExactAge(70),
                'expectedAge'     => 70,
                'expectedMult'    => '1.50',
                'expectedPremium' => '18.00',
            ],
            'still in 61-70 band: one day before 71st birthday' => [
                'dob'             => self::dobOneDayBeforeAging(71), // not yet 71
                'expectedAge'     => 70,
                'expectedMult'    => '1.50',
                'expectedPremium' => '18.00',
            ],

            // ── Band 71+ (×2.00) ─────────────────────────────────────────────
            'lower edge of 71+ band: exactly age 71' => [
                'dob'             => self::dobForExactAge(71),
                'expectedAge'     => 71,
                'expectedMult'    => '2.00',
                'expectedPremium' => '24.00',  // 12 × 2.00
            ],
        ];
    }

    // ── Travel day calculation ─────────────────────────────────────────────────

    #[Test]
    public function same_day_trip_counts_as_one_day(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START), // same day
            destination: Destination::Domestic,
            sumInsured:  100_000,
        );

        $this->assertSame(1, $result->breakdown['travel_days']);
        // 12 × 1 × 1.00 × 1.00 × 1.00 = 12.00
        $this->assertSame('12.00', $result->premiumAmount);
    }

    #[Test]
    public function multi_day_trip_counts_days_inclusively(): void
    {
        // Jun 1 → Jun 7 = 7 inclusive days
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d('2024-06-01'),
            tripEnd:     self::d('2024-06-07'),
            destination: Destination::Domestic,
            sumInsured:  100_000,
        );

        $this->assertSame(7, $result->breakdown['travel_days']);
        $this->assertSame('84.00', $result->premiumAmount); // 12 × 7
    }

    // ── Plan code validation ───────────────────────────────────────────────────

    #[Test]
    public function basic_plan_uses_rate_12(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START),
            destination: Destination::Domestic,
            sumInsured:  100_000,
        );
        $this->assertSame('12', $result->breakdown['base_daily_rate']);
    }

    #[Test]
    public function premium_plan_uses_rate_30(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'PREMIUM',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START),
            destination: Destination::Domestic,
            sumInsured:  100_000,
        );
        $this->assertSame('30', $result->breakdown['base_daily_rate']);
        $this->assertSame('30.00', $result->premiumAmount); // 30 × 1 × 1.00 × 1.00 × 1.00
    }

    #[Test]
    public function unsupported_plan_code_throws_domain_exception(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Unsupported plan code/i');

        $this->calculator->calculate(
            planCode:    'GOLD',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START),
            destination: Destination::Domestic,
            sumInsured:  100_000,
        );
    }

    // ── Coverage validation ────────────────────────────────────────────────────

    #[Test]
    public function unsupported_sum_insured_throws_domain_exception(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Unsupported sum insured/i');

        $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START),
            destination: Destination::Domestic,
            sumInsured:  200_000, // not in supported list
        );
    }

    // ── Destination multiplier ─────────────────────────────────────────────────

    #[Test]
    public function domestic_destination_applies_1x_multiplier(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START),
            destination: Destination::Domestic,
            sumInsured:  100_000,
        );
        $this->assertSame('1.00', $result->breakdown['destination_multiplier']);
        $this->assertSame('12.00', $result->premiumAmount);
    }

    #[Test]
    public function international_destination_applies_1_80_multiplier(): void
    {
        // 12 × 1 × 1.00 × 1.80 × 1.00 = 21.60
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START),
            destination: Destination::International,
            sumInsured:  100_000,
        );
        $this->assertSame('1.80', $result->breakdown['destination_multiplier']);
        $this->assertSame('21.60', $result->premiumAmount);
    }

    // ── Coverage multiplier ────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('coverageMultiplierProvider')]
    public function coverage_multiplier_applied_correctly(
        int    $sumInsured,
        string $expectedMultiplier,
        string $expectedPremium,
    ): void {
        // BASIC, DOMESTIC, age 30 (×1.00), 1 day → premium = 12 × coverage_mult
        $result = $this->calculator->calculate(
            planCode:    'BASIC',
            dateOfBirth: self::d(self::dobForExactAge(30)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d(self::TRIP_START),
            destination: Destination::Domestic,
            sumInsured:  $sumInsured,
        );

        $this->assertSame($expectedMultiplier, $result->breakdown['coverage_multiplier']);
        $this->assertSame($expectedPremium,    $result->premiumAmount);
    }

    public static function coverageMultiplierProvider(): array
    {
        return [
            '100_000 → ×1.00' => [100_000, '1.00', '12.00'],
            '300_000 → ×1.25' => [300_000, '1.25', '15.00'], // 12 × 1.25
            '500_000 → ×1.50' => [500_000, '1.50', '18.00'], // 12 × 1.50
        ];
    }

    // ── Rounding ───────────────────────────────────────────────────────────────

    #[Test]
    public function result_is_always_rounded_to_two_decimal_places(): void
    {
        // PREMIUM=30, INTERNATIONAL×1.80, age17×1.10, 500k×1.50, 3 days
        // 30 × 3 × 1.10 × 1.80 × 1.50 = 267.30
        $result = $this->calculator->calculate(
            planCode:    'PREMIUM',
            dateOfBirth: self::d(self::dobForExactAge(17)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d('2024-06-03'),
            destination: Destination::International,
            sumInsured:  500_000,
        );

        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $result->premiumAmount);
        $this->assertSame('267.30', $result->premiumAmount);
    }

    // ── Return type & breakdown completeness ───────────────────────────────────

    #[Test]
    public function result_contains_all_required_breakdown_keys(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'PLUS',
            dateOfBirth: self::d(self::dobForExactAge(28)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d('2024-06-05'),
            destination: Destination::International,
            sumInsured:  300_000,
        );

        $requiredKeys = [
            'plan_code', 'base_daily_rate', 'travel_days',
            'age_at_trip_start', 'age_multiplier',
            'destination', 'destination_multiplier',
            'sum_insured', 'coverage_multiplier',
            'premium_amount',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result->breakdown, "Missing breakdown key: {$key}");
        }
    }

    #[Test]
    public function premium_amount_on_result_matches_breakdown(): void
    {
        $result = $this->calculator->calculate(
            planCode:    'PLUS',
            dateOfBirth: self::d(self::dobForExactAge(28)),
            tripStart:   self::d(self::TRIP_START),
            tripEnd:     self::d('2024-06-05'),
            destination: Destination::International,
            sumInsured:  300_000,
        );

        $this->assertSame($result->premiumAmount, $result->breakdown['premium_amount']);
    }
}
