<?php

namespace Tests\Unit;

use App\HotPath\QueryParams;
use PHPUnit\Framework\TestCase;

class HotPathQueryParamsTest extends TestCase
{
    public function test_plain_params(): void
    {
        $params = QueryParams::parse('zone=8841&creative=17');

        $this->assertSame(['zone' => '8841', 'creative' => '17'], $params);
    }

    public function test_empty_query_string(): void
    {
        $params = QueryParams::parse('');

        $this->assertSame([], $params);
    }

    public function test_last_value_wins(): void
    {
        $params = QueryParams::parse('zone=1&zone=2');

        $this->assertSame(['zone' => '2'], $params);
    }

    public function test_plus_and_percent_are_decoded(): void
    {
        $params = QueryParams::parse('zone=a+b%26c&na%20me=%7Bzoneid%7D');

        $this->assertSame(['zone' => 'a b&c', 'na me' => '{zoneid}'], $params);
    }

    public function test_names_are_kept_as_is(): void
    {
        $params = QueryParams::parse('a.b=1&sub[1]=2');

        $this->assertSame(['a.b' => '1', 'sub[1]' => '2'], $params);
    }

    public function test_empty_name_is_ignored(): void
    {
        $params = QueryParams::parse('=1&&zone=2&=');

        $this->assertSame(['zone' => '2'], $params);
    }

    public function test_pair_without_equals_has_empty_value(): void
    {
        $params = QueryParams::parse('flag&zone={zoneid}&creative=');

        $this->assertSame(['flag' => '', 'zone' => '{zoneid}', 'creative' => ''], $params);
    }

    public function test_value_is_split_at_first_equals(): void
    {
        $params = QueryParams::parse('u=a=b');

        $this->assertSame(['u' => 'a=b'], $params);
    }

    public function test_invalid_utf8_is_replaced(): void
    {
        $params = QueryParams::parse('z%FF=a%C3b');

        $this->assertSame(["z\u{FFFD}" => "a\u{FFFD}b"], $params);
    }

    public function test_only_first_50_names_are_kept(): void
    {
        $pairs = [];

        for ($i = 1; $i <= 60; $i++) {
            $pairs[] = "p{$i}={$i}";
        }

        $pairs[] = 'p1=last';
        $queryString = implode('&', $pairs);

        $params = QueryParams::parse($queryString);

        $this->assertCount(50, $params);
        $this->assertSame('p1', array_key_first($params));
        $this->assertSame('p50', array_key_last($params));
        $this->assertSame('last', $params['p1']);
    }

    public function test_long_name_and_value_are_truncated(): void
    {
        $name = str_repeat('n', 100);
        $value = str_repeat('v', 5000);

        $params = QueryParams::parse("{$name}={$value}");

        $truncatedName = str_repeat('n', 64);

        $this->assertSame([$truncatedName => str_repeat('v', 1024)], $params);
    }

    public function test_limits_count_unicode_characters(): void
    {
        $name = str_repeat('ї', 100);
        $value = str_repeat('😀', 5000);
        $queryString = rawurlencode($name).'='.rawurlencode($value);

        $params = QueryParams::parse($queryString);

        $truncatedName = str_repeat('ї', 64);

        $this->assertSame([$truncatedName => str_repeat('😀', 1024)], $params);
    }

    public function test_names_equal_after_truncation_are_one_param(): void
    {
        $prefix = str_repeat('n', 64);

        $params = QueryParams::parse("{$prefix}a=1&{$prefix}b=2");

        $this->assertSame([$prefix => '2'], $params);
    }
}
