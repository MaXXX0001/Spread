<?php

namespace App\Clicks;

use DeviceDetector\DeviceDetector;

/**
 * Device type, OS, browser and bot flag of a click by its User-Agent (design D13).
 */
final class DeviceDetection
{
    // One detector for the whole worker: its parsed regex files live in a static cache anyway.
    private readonly DeviceDetector $detector;

    public function __construct()
    {
        $this->detector = new DeviceDetector;
        $this->detector->discardBotInformation();
    }

    /**
     * @return array{device_type: ?string, os_name: ?string, os_version: ?string, browser_name: ?string, browser_version: ?string, is_bot: bool}
     */
    public function detect(?string $userAgent): array
    {
        $unknown = [
            'device_type' => null,
            'os_name' => null,
            'os_version' => null,
            'browser_name' => null,
            'browser_version' => null,
            'is_bot' => true,
        ];

        if ($userAgent === null || $userAgent === '') {
            return $unknown;
        }

        $this->detector->setUserAgent($userAgent);
        $this->detector->parse();

        if ($this->detector->isBot()) {
            return $unknown;
        }

        $deviceName = $this->detector->getDeviceName();
        $osName = $this->detector->getOs('name');
        $osVersion = $this->detector->getOs('version');
        $browserName = $this->detector->getClient('name');
        $browserVersion = $this->detector->getClient('version');

        return [
            'device_type' => self::known($deviceName),
            'os_name' => self::known($osName),
            'os_version' => self::known($osVersion),
            'browser_name' => self::known($browserName),
            'browser_version' => self::known($browserVersion),
            'is_bot' => false,
        ];
    }

    private static function known(string $value): ?string
    {
        return $value === '' || $value === DeviceDetector::UNKNOWN ? null : $value;
    }
}
