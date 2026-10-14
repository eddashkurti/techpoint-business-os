<?php

declare(strict_types=1);

namespace App\Services;

use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

final class ProductLabel
{
    public function svg(string $code): string
    {
        if (! preg_match('/^[\x20-\x7E]{1,60}$/D', $code)) {
            throw new \DomainException('Etiketa Code 128 kërkon 1–60 shenja ASCII. Caktoni një barkod të përshtatshëm.');
        }
        $barcode = (new TypeCode128)->getBarcode($code);
        $renderer = new SvgRenderer;
        $renderer->setSvgType(SvgRenderer::TYPE_SVG_INLINE);

        return $renderer->render($barcode, $barcode->getWidth() * 2, 60);
    }
}
