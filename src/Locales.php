<?php

namespace Karpack\Translations;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Karpack\Translations\Exceptions\LocaleNotSupportedException;
use Karpack\Translations\Models\Locale;

class Locales
{
    /**
     * Cache of locale codes mapped to their id's
     * 
     * @var array
     */
    protected $localeIds = [];

    /**
     * Flag that determines whether this service was booted or not
     * 
     * @var bool
     */
    protected $isBooted = false;

    /**
     * Default locale code
     * 
     * @var string
     */
    protected $defaultLocale = 'en';

    /**
     * Boots the locale service by loading all the locale and caching it
     * 
     * @return $this
     */
    public function boot()
    {
        if ($this->isBooted) {
            return $this;
        }
        $this->localeIds = Cache::get(self::class);

        if (is_null($this->localeIds)) {
            $this->cache();
        }
        $this->isBooted = true;

        return $this;
    }

    /**
     * Saves the locales onto the db
     * 
     * @return void
     */
    public function seed()
    {
        $locales = collect(require __DIR__ . '/Data/locales.php');

        DB::transaction(function () use ($locales) {
            // Split the locales into chunks of 100. We'll insert 100 locales in one
            // go using the ORM insert query for efficiency.
            $locales->chunk(100)->each(function (Collection $chunk) {

                // Loop through each chunk and return an array containing the data to be 
                // inserted. Invalid data might return null, so that should be filtered.
                $data = $chunk->map(function ($localeData, $localeCode) {
                    return $this->localeInsertData($localeCode, $localeData);
                })->filter()->all();

                Locale::insert($data);
            });
        });
    }

    /**
     * Returns an array of data that can be inserted into db directly ie columns mapped to
     * their values. Any errors, returns null.
     * 
     * @param string $localeCode
     * @param array|null $localeData
     * @return array|null
     */
    private function localeInsertData($localeCode, $localeData)
    {
        if (!is_array($localeData)) {
            return $localeData;
        }
        $name = Arr::get($localeData, 'name');

        if (is_null($name)) {
            return null;
        }
        $locale = [
            'iso_code' => $localeCode,
            'name' => $name
        ];

        if (array_key_exists('charset', $localeData)) {
            $locale['charset'] = $localeData['charset'];
        }

        if (array_key_exists('rtl', $localeData)) {
            $locale['rtl'] = !!$localeData['rtl'];
        }
        return $locale;
    }

    /**
     * Adds a new locale using the given data and returns it
     * 
     * @param array $data
     * @return \Karpack\Translations\Models\Locale
     * @throws \Illuminate\Validation\ValidationException
     */
    public function add(array $data)
    {
        Validator::make($data, [
            'name' => 'required',
            'iso_code' => 'required|unique:locales'
        ])->validate();

        $data = $this->localeInsertData($data['iso_code'], $data);

        $locale = new Locale;

        foreach ($data as $key => $value) {
            $locale->setAttribute($key, $value);
        }
        $locale->save();

        $this->cache();

        return $locale;
    }

    /**
     * Sets a new default locale, if our application supports it. Otherwise, throws
     * exception.
     * 
     * @param string $locale
     * @return $this
     * @throws \Karpack\Translations\Exceptions\LocaleNotSupportedException
     */
    public function setDefaultLocale($locale)
    {
        if (!$this->supports($locale)) {
            throw new LocaleNotSupportedException($locale);
        }
        $this->defaultLocale = $locale;

        return $this;
    }

    /**
     * Caches the locale ids
     * 
     * @return $this
     */
    public function cache()
    {
        $cache = [];

        foreach (Locale::all() as $locale) {
            $cache[$locale->iso_code] = $locale->getKey();
        }
        $this->localeIds = $cache;

        Cache::put(self::class, $this->localeIds);

        return $this;
    }

    /**
     * Clears the locale id cache
     * 
     * @return $this
     */
    public function clearCache()
    {
        Cache::forget(self::class);

        $this->localeIds = [];

        $this->isBooted = false;

        return $this;
    }

    /**
     * Resets the locale service by clearing the cache and setting the default locale
     * back to `en`, which is the default locale
     * 
     * @return $this
     */
    public function reset()
    {
        $this->clearCache();

        $this->defaultLocale = 'en';

        return $this;
    }

    /**
     * Returns the id of the given locale string.
     * 
     * @param string $locale
     * @return int
     */
    public function id($locale)
    {
        if ($this->supports($locale)) {
            return $this->localeIds[$locale];
        }
        return $this->localeIds[$this->defaultLocale];
    }

    /**
     * Returns the default locale 
     * 
     * @return string
     */
    public function defaultLocale()
    {
        return $this->defaultLocale;
    }

    /**
     * Returns the default locale id
     * 
     * @return int
     */
    public function defaultLocaleId()
    {
        return $this->id($this->defaultLocale());
    }

    /**
     * Returns true if the application supports the given locale
     * 
     * @param string $locale
     * @return bool
     */
    public function supports($locale)
    {
        $this->boot();

        return array_key_exists($locale, $this->localeIds);
    }
}
