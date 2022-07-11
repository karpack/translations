<?php

use Karpack\Translations\Locales;

if (!function_exists('locale')) {
    /**
     * Get the locale repo/service
     *
     * @return \Karpack\Translations\Locales
     */
    function locales()
    {
        return container()->make(Locales::class);
    }
}

if (!function_exists('locale_id')) {
    /**
     * Get the locale_id corresponding to the given locale string
     *
     * @return int
     */
    function locale_id(string $locale)
    {
        return locales()->id($locale);
    }
}
