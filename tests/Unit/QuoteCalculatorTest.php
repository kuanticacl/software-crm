<?php

namespace Tests\Unit;

use App\Services\RealEstate\QuoteCalculator;
use PHPUnit\Framework\TestCase;

class QuoteCalculatorTest extends TestCase
{
    public function test_unit_plus_parking_with_discount_pie_and_credit(): void
    {
        $t = QuoteCalculator::totals([
            ['list_price_uf' => 3500, 'discount_pct' => 5],   // departamento
            ['list_price_uf' => 300],                        // estacionamiento
            ['list_price_uf' => 80, 'discount_pct' => 100],  // bodega de regalo
        ], 20, 4.5, 25);

        $this->assertEquals([3325, 300, 0], $t['items']);
        $this->assertEquals(3880, $t['subtotal_uf']);
        $this->assertEquals(255, $t['discount_uf']);
        $this->assertEquals(3625, $t['total_uf']);
        $this->assertEquals(725, $t['pie_uf']);
        $this->assertEquals(2900, $t['credit_uf']);
        // 2.900 UF a 25 años al 4,5% anual ≈ 16,12 UF mensuales
        $this->assertEqualsWithDelta(16.12, $t['monthly_payment_uf'], 0.01);
    }

    public function test_pie_installments_discount_the_reservation_and_add_up(): void
    {
        $plan = QuoteCalculator::pieInstallments(725, 7.5, 24, new \DateTimeImmutable('2026-11-05'));

        $this->assertCount(24, $plan);
        $this->assertEquals('2026-11-05', $plan[0]['due_date']);
        $this->assertEquals('2028-10-05', $plan[23]['due_date']);
        $this->assertEqualsWithDelta(717.5, array_sum(array_column($plan, 'amount')), 0.001);
    }
}
