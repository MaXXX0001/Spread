<?php

namespace Tests\Unit;

use App\Postbacks\ConversionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConversionStatusTest extends TestCase
{
    /**
     * @return array<string, array{ConversionStatus, ConversionStatus, bool}>
     */
    public static function transitionProvider(): array
    {
        $allowed = [
            'lead' => ['hold', 'approved', 'rejected'],
            'hold' => ['approved', 'rejected'],
            'approved' => ['rejected'],
            'rejected' => ['approved'],
        ];
        $cases = [];

        foreach (ConversionStatus::cases() as $from) {
            foreach (ConversionStatus::cases() as $to) {
                $isAllowed = in_array($to->value, $allowed[$from->value], true);
                $cases["{$from->value} -> {$to->value}"] = [$from, $to, $isAllowed];
            }
        }

        return $cases;
    }

    #[DataProvider('transitionProvider')]
    public function test_transition(ConversionStatus $from, ConversionStatus $to, bool $isAllowed): void
    {
        $this->assertSame($isAllowed, $from->canTransitionTo($to));
    }

    public function test_all_sixteen_pairs_are_covered(): void
    {
        $cases = self::transitionProvider();

        $this->assertCount(16, $cases);
    }
}
