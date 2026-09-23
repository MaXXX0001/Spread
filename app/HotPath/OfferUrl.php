<?php

namespace App\HotPath;

final class OfferUrl
{
    private const string PLACEHOLDER = '/\{([A-Za-z0-9_]{1,64})\}/';

    /**
     * @param  array<string, string>  $params
     */
    public static function build(string $template, string $clickId, array $params): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match): string => $match[1] === 'click_id'
                ? $clickId
                : rawurlencode($params[$match[1]] ?? ''),
            $template,
        );
    }
}
