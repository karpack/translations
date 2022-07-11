<?php

namespace Karpack\Translations\Exceptions;

use Exception;

class LocaleNotSupportedException extends Exception
{
    public function __construct($locale)
    {
        parent::__construct("Locale $locale is not supported by the application");
    }
}
