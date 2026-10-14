<?php

namespace Tests\Unit\Domain;

use App\Services\ProductLabel;
use PHPUnit\Framework\TestCase;

class ProductLabelTest extends TestCase
{
    public function test_code128_label_has_svg_and_bars_without_embedded_user_markup(): void
    {
        $svg = (new ProductLabel)->svg('TECH-12345');
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringNotContainsString('<script', $svg);
    }

    public function test_non_ascii_codes_are_refused_instead_of_printing_unscannable_labels(): void
    {
        $this->expectException(\DomainException::class);
        (new ProductLabel)->svg('pajisje-ë');
    }
}
