<?php

namespace Tests\Unit;

use App\Services\InvoiceTaxService;
use PHPUnit\Framework\TestCase;

class InvoiceTaxServiceTest extends TestCase
{
    public function test_every_structured_service_category_is_taxed_only_when_selected(): void
    {
        $service = new InvoiceTaxService;
        $types = ['pricing' => 'flights', 'hotel' => 'hotels', 'transfer' => 'transfers', 'city_tour' => 'city_tours', 'visa' => 'visa', 'other_service' => 'other_services'];
        foreach ($types as $type => $category) {
            $this->assertSame($category, $service->category(['type' => $type]));
            $settings = [...InvoiceTaxService::defaults(), 'enabled' => true, 'rate' => '10', 'categories' => [$category], 'precision' => 2];
            $lines = array_map(fn ($value) => ['total_price' => 100, 'tax_category' => $value], array_values($types));
            $lines[] = ['total_price' => -60, 'tax_category' => null];
            $totals = $service->totals($lines, $settings);
            $this->assertEquals(9, $totals['tax_amount']);
            $this->assertEquals(549, $totals['total_amount']);
            $this->assertEquals(90, $totals['taxable_amount']);
        }
        $this->assertNull($service->category(['description' => 'Flight hotel visa']));
        $this->assertNull($service->category(['type' => 'discount']));
        $this->assertSame('flights', $service->category(['type' => 'pricing_total']));
    }
}
