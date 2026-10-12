<?php

namespace Tests\Unit;

use App\HotPath\OfferUrl;
use PHPUnit\Framework\TestCase;

class HotPathOfferUrlTest extends TestCase
{
    private const string CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

    public function test_click_id_wins_over_param(): void
    {
        $url = OfferUrl::build('https://o.test/?s={click_id}', self::CLICK_ID, ['click_id' => 'fake']);

        $this->assertSame('https://o.test/?s='.self::CLICK_ID, $url);
    }

    public function test_value_is_rfc3986_encoded(): void
    {
        $url = OfferUrl::build('https://o.test/?s={click_id}&z={zone}', self::CLICK_ID, ['zone' => 'a b&c']);

        $this->assertSame('https://o.test/?s='.self::CLICK_ID.'&z=a%20b%26c', $url);
    }

    public function test_missing_param_is_empty(): void
    {
        $url = OfferUrl::build('https://o.test/?z={zone}&c={creative}', self::CLICK_ID, ['creative' => '17']);

        $this->assertSame('https://o.test/?z=&c=17', $url);
    }

    public function test_names_are_case_sensitive(): void
    {
        $url = OfferUrl::build('https://o.test/?z={Zone}', self::CLICK_ID, ['zone' => '1']);

        $this->assertSame('https://o.test/?z=', $url);
    }

    public function test_non_placeholder_braces_stay(): void
    {
        $tooLong = str_repeat('x', 65);
        $template = "https://o.test/?a={не плейсхолдер}&b={a-b}&c={}&d={{$tooLong}}&e={zone}";

        $url = OfferUrl::build($template, self::CLICK_ID, ['zone' => '1']);

        $expected = "https://o.test/?a={не плейсхолдер}&b={a-b}&c={}&d={{$tooLong}}&e=1";

        $this->assertSame($expected, $url);
    }

    public function test_numeric_and_underscore_names(): void
    {
        $url = OfferUrl::build('https://o.test/?a={1}&b={sub_2}', self::CLICK_ID, ['1' => 'x', 'sub_2' => 'y']);

        $this->assertSame('https://o.test/?a=x&b=y', $url);
    }
}
