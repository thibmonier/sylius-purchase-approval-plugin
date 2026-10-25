<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tmonier\SyliusPurchaseApprovalPlugin\DependencyInjection\Configuration;

final class AmountBasisConfigurationTest extends TestCase
{
    public function testTheDefaultBasisIsTheTotalTaxIncluded(): void
    {
        self::assertSame('gross', $this->process([])['amount_basis']);
    }

    public function testTheNetBasisCanBeChosen(): void
    {
        self::assertSame('net', $this->process(['amount_basis' => 'net'])['amount_basis']);
    }

    public function testAnUnknownBasisIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['amount_basis' => 'half']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
