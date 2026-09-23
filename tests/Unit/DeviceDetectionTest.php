<?php

namespace Tests\Unit;

use App\Clicks\DeviceDetection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeviceDetectionTest extends TestCase
{
    private const string ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.127 Mobile Safari/537.36';

    private const string WINDOWS_FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';

    public function test_chrome_on_android_smartphone(): void
    {
        $device = (new DeviceDetection)->detect(self::ANDROID_CHROME);

        $this->assertSame('smartphone', $device['device_type']);
        $this->assertSame('Android', $device['os_name']);
        $this->assertSame('14', $device['os_version']);
        $this->assertSame('Chrome Mobile', $device['browser_name']);
        $this->assertNotNull($device['browser_version']);
        $this->assertFalse($device['is_bot']);
    }

    public function test_firefox_on_windows_desktop(): void
    {
        $device = (new DeviceDetection)->detect(self::WINDOWS_FIREFOX);

        $this->assertSame('desktop', $device['device_type']);
        $this->assertSame('Windows', $device['os_name']);
        $this->assertSame('Firefox', $device['browser_name']);
        $this->assertNotNull($device['browser_version']);
        $this->assertFalse($device['is_bot']);
    }

    public function test_googlebot_is_bot(): void
    {
        $device = (new DeviceDetection)->detect('Googlebot/2.1 (+http://www.google.com/bot.html)');

        $this->assertTrue($device['is_bot']);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingUserAgents(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('missingUserAgents')]
    public function test_missing_user_agent_is_bot_without_device(?string $userAgent): void
    {
        $device = (new DeviceDetection)->detect($userAgent);

        $this->assertSame([
            'device_type' => null,
            'os_name' => null,
            'os_version' => null,
            'browser_name' => null,
            'browser_version' => null,
            'is_bot' => true,
        ], $device);
    }

    public function test_one_instance_detects_different_user_agents(): void
    {
        $detection = new DeviceDetection;

        $android = $detection->detect(self::ANDROID_CHROME);
        $windows = $detection->detect(self::WINDOWS_FIREFOX);
        $androidAgain = $detection->detect(self::ANDROID_CHROME);

        $this->assertSame('Android', $android['os_name']);
        $this->assertSame('Windows', $windows['os_name']);
        $this->assertSame($android, $androidAgain);
    }
}
