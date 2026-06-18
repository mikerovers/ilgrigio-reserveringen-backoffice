<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TicketCodeGenerator;
use PHPUnit\Framework\TestCase;

class TicketCodeGeneratorTest extends TestCase
{
    private TicketCodeGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TicketCodeGenerator();
    }

    public function testCodeHasPrefixAndIsUrlSafe(): void
    {
        $code = $this->generator->generate();

        $this->assertStringStartsWith('TKT-', $code);
        $this->assertMatchesRegularExpression('/^TKT-[0-9A-HJ-NP-TV-Z]+$/', $code);
    }

    public function testCodesAreUniqueAcrossManyGenerations(): void
    {
        $codes = [];
        for ($i = 0; $i < 1000; $i++) {
            $codes[$this->generator->generate()] = true;
        }

        $this->assertCount(1000, $codes, 'Generated codes must not collide');
    }

    public function testExcludesAmbiguousCharacters(): void
    {
        $code = substr($this->generator->generate(), 4);

        $this->assertStringNotContainsString('I', $code);
        $this->assertStringNotContainsString('L', $code);
        $this->assertStringNotContainsString('O', $code);
        $this->assertStringNotContainsString('U', $code);
    }
}
