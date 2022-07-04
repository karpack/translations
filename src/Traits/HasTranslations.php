<?php

namespace Karpack\Translations\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Karpack\Support\Traits\HasAdditionalProperties;
use Karpack\Translations\Models\Locale;
use Karpack\Translations\Models\Translation;

trait HasTranslations
{
    use HasAdditionalProperties;

    /**
     * Translations grouped by their locale id.
     * 
     * @var \Illuminate\Support\Collection
     */
    protected $cachedLocaleTranslations;

    /**
     * Cache of all the locales registered on the app.
     * 
     * @var \Illuminate\Support\Collection
     */
    protected $cachedLocales;

    /**
     * Returns the keys/properties that can have different translations.
     * 
     * @return array
     */
    public abstract function translationKeys();

    /**
     * Trait boot function which will be called whenever a model is booted. We add a model delete 
     * event, so that the translation models are deleted whenever the model is deleted. For 
     * example, when a Product is deleted, all the translations belonging to the model are also 
     * deleted automatically.
     * 
     * NOTE: DO NOT CHANGE THE NAME OF THE FUNCTION. IT HAS TO BE `boot + name_of_the_trait`
     */
    public static function bootHasTranslations()
    {
        static::deleting(function ($model) {
            // If the method `isForceDeleting` does not exists, then the model does not
            // use SoftDeletes trait, so we will delete the translations. If the method
            // exists, we will call the function and see if the delete is a force delete
            // and if it is we will delete the translations.
            if (!method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                $model->deleteTranslations();
            }
        });
    }

    /**
     * Hides the translation on all the array conversion
     * 
     * @return void
     */
    public function initializeHasTranslations()
    {
        $this->makeHidden('translations');
    }

    /**
     * Returns all the translations of this model.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function translations()
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    /**
     * Deletes all the translation models of this model. 
     * 
     * Done by calling the `HasMany`relation, which returns all the translations, and 
     * performing a delete operation on it.
     */
    public function deleteTranslations()
    {
        $this->translations->delete();
    }

    /**
     * Set the given relationship on the model.
     * 
     * Overridden function from the Eloquent\Model class. This sets the relation value on the
     * model. In addition to setting the relation, which is done by the base Model, we call 
     * `loadTranslations()` to add the translations on to this model.
     *
     * @param string $relation
     * @param mixed $value
     * 
     * @return $this
     */
    public function setRelation($relation, $value)
    {
        $returnValue = parent::setRelation($relation, $value);

        if ($relation === 'translations') {
            $this->loadTranslation($value);
        }
        return $returnValue;
    }

    /**
     * Loads the translations (given as parameter or loaded from relation) as attributes
     * on this model. This facilitates the current model, direct access to the property values 
     * using the property name.
     * 
     * After adding all the translated properties as attribute to this model, we will hide the 
     * relation from the model.
     * 
     * @param array|null $translations
     * @return $this
     */
    public function loadTranslation($translations = null)
    {
        $translations = $translations ?: $this->translations;

        // Get a grouped collection of all the translations. From this collection, we'll 
        // load the translation we need for the current request. Either we'll load the 
        // current request locale, or the default locale or finally the first available
        // locale.
        $translations = $this->groupTranslationsByLocale($translations);

        // If the grouped translations is empty, we don't have any translation, so we'll
        // simply return
        if ($translations->isEmpty()) {
            return $this;
        }

        $translation = $this->translationOfCurrentRequest($translations);

        foreach ($translation ?: array() as $property) {
            $this->setRawAdditionalProperty($property->property, $property->property_value);
        }
        return $this;
    }

    /**
     * Returns the translation for the current request.
     * 
     * @param \Illuminate\Support\Collection $translations
     * @return \Illuminate\Support\Collection
     */
    protected function translationOfCurrentRequest($translations)
    {
        $currentLocale = $this->locales()->where('iso_code', App::getLocale())->first();

        // If the current request locale is not registered on the application, we'll use
        // english as the current locale.
        if (is_null($currentLocale)) {
            $currentLocale = $this->locales()->where('iso_code', Locale::ENGLISH_CODE)->first();
        }

        if (!is_null($currentLocale)) {
            // We have a valid currentLocale model. Check whether we have the translation for the same
            // If so, return that translation.
            if ($translations->has($localeId = $currentLocale->getKey())) {
                return $translations->get($localeId);
            }

            // No translation exists for the current local key. Try to send the english translations by
            // default.
            if ($translations->has(Locale::ENGLISH)) {
                return $translations->get(Locale::ENGLISH);
            }
        }
        return $translations->first();
    }

    /**
     * Loads all the application locales
     * 
     * @param bool $reload
     * @return \Illuminate\Database\Eloquent\Collection<\Karpack\Translations\Models\Locale>
     */
    protected function locales($reload = false)
    {
        if (!$reload && isset($this->cachedLocales)) {
            return $this->cachedLocales;
        }
        $this->cachedLocales = Cache::get('locales');

        if (is_null($this->cachedLocales)) {
            $this->cachedLocales = Locale::all();

            Cache::put('locales', $this->cachedLocales);
        }
        return $this->cachedLocales;
    }

    /**
     * Caches the given translation-property-value models. The property models should belong
     * to a single localeId, as all of them will be cached based on the locale_id of the
     * first element in the collection.
     * 
     * @param \Illuminate\Support\Collection $translationProperties
     */
    protected function cacheTranslationProperties($translationProperties)
    {
        if (empty($translationProperties)) {
            return;
        }
        $translationProperty = $translationProperties->first();

        if (empty($translationProperty)) {
            return;
        }
        $this->getCachedLocaleTranslations()->put($translationProperty->locale_id, $translationProperties);
    }

    /**
     * Returns the cached locale translations.
     * 
     * @return \Illuminate\Support\Collection
     */
    protected function getCachedLocaleTranslations()
    {
        if (!isset($this->cachedLocaleTranslations)) {
            $this->cachedLocaleTranslations = collect();
        }
        return $this->cachedLocaleTranslations;
    }

    /**
     * Gets the translation-property-value models for the default locale ie English.
     * 
     * @return \Illuminate\Support\Collection
     */
    protected function getDefaultLocaleTranslation()
    {
        return $this->getTranslationOfLocale(Locale::ENGLISH);
    }

    /**
     * Returns all the translation-property-value models of the given locale.
     * 
     * @param int $localeId
     * @return \Illuminate\Support\Collection
     */
    protected function getTranslationOfLocale($localeId)
    {
        $cachedLocaleTranslations = $this->getCachedLocaleTranslations();

        if ($cachedLocaleTranslations->has($localeId)) {
            return $cachedLocaleTranslations->get($localeId);
        }
        $translations = $this->translations()->where('locale_id', $localeId)->get();

        $cachedLocaleTranslations->put($localeId, $translations);

        return $translations;
    }

    /**
     * Here we will load all the translation-property-value models and group them by locale_id 
     * and returns the first entry in the collection.
     * 
     * @return \Illuminate\Support\Collection
     */
    protected function getFirstAvailableTranslation()
    {
        return $this->groupTranslationsByLocale($this->translations)->first();
    }

    /**
     * Groups the given translations by locale_id and caches them. Returns the grouped
     * translation collection.
     * 
     * @param \Illuminate\Support\Collection $translations
     * @return \Illuminate\Support\Collection
     */
    protected function groupTranslationsByLocale($translations)
    {
        $translations = $translations->groupBy('locale_id');

        $this->cachedLocaleTranslations = $translations;

        return $translations;
    }

    /**
     * Returns a translation-property-value model for the given key if one exists or returns 
     * undefined.
     * 
     * @param string $propertyKey
     * @param int $localeId
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getTranslationModel($propertyKey, $localeId)
    {
        $translations = $this->getTranslationOfLocale($localeId);

        return $translations->first(function ($property) use ($propertyKey) {
            return $property->property === $propertyKey;
        });
    }

    /**
     * Saves the given translations on the model. If a translations model exists for the key, 
     * then that model is updated, otherwise a new model is created. If the second argument 
     * `$allowAllProps` is not set to true, only the properties returned in the `$this->properties()` 
     * array will be saved. By default, this argument is set to false.
     * 
     * @param array $properties 
     * @param int $localeId
     * @param bool $allowAllProps
     * @return static
     */
    public function saveTranslations(array $properties, $localeId, $allowAllProps = false)
    {
        $allowedTranslationProps = $this->translationKeys() ?: [];

        foreach ($properties ?: [] as $key => $value) {
            // Update only if the property key exists in the model properties list 
            // or if the `allowAllProps` flag is set.
            if (!in_array($key, $allowedTranslationProps) && !$allowAllProps) {
                continue;
            }
            $transModel = $this->getTranslationModel($key, $localeId) ?: $this->createTranslation($key, $localeId);
            $mutatedValue = $this->mutatedPropertyValue($transModel->property, $value);
            $transModel->property_value = $mutatedValue;

            if ($this->translations()->save($transModel)) {
                // If a new translation model is created, we will cache it to the Locale collections. 
                // `getTransactionOfLocale` gets the cached collection of translation of the given 
                // locale. So, we will just push the newly created model into that collection.
                if ($transModel->wasRecentlyCreated) {
                    $this->getTranslationOfLocale($localeId)->add($transModel);
                }
                $this->setRawAdditionalProperty($key, $mutatedValue);
            }
        }
        return $this;
    }

    /**
     * Saves all the translation data in the given data collection. This is done by looping
     * through all the `property_translations` object in the collection and saving it.
     * 
     * @param \Illuminate\Support\Collection $data
     * @param bool $allowAllProps
     * @return static
     */
    public function saveAllTranslations(Collection $data, $allowAllProps = false)
    {
        $translations = collect($data->get('property_translations', []));

        $translations->each(function ($translation) use ($allowAllProps) {
            $translation = collect($translation);

            // Check if the field contains locale_id field for saving the translation.
            // If it does, then save the properties for the given locale.
            if ($localeId = $translation->pull('locale_id')) {
                $this->saveTranslations($translation->all(), $localeId, $allowAllProps);
            }
        });

        return $this;
    }

    /**
     * Creates a new translation model for the given key and returns it.
     * 
     * @param string $propertyKey
     * @param int $localeId
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function createTranslation($propertyKey, $localeId)
    {
        $translation = new Translation();
        $translation->locale_id = $localeId;
        $translation->property = $propertyKey;
        $translation->translatable()->associate($this);

        return $translation;
    }

    /**
     * Returns the properties of this model as key-value pair for the given localeId.
     * 
     * @param int $localeId
     * @return \Illuminate\Support\Collection
     */
    public function getTranslation($localeId)
    {
        $properties = $this->getTranslationOfLocale($localeId);

        return $this->getPropertiesOfLocale($properties, $localeId);
    }

    /**
     * Returns a collection mapping attributes(properties) to their values from the given 
     * $propertyModels.
     * 
     * @param \Illuminate\Support\Collection $propertyModels
     * @param int $localeId
     * @return \Illuminate\Support\Collection
     */
    protected function getPropertiesOfLocale($propertyModels, $localeId)
    {
        // The parameter $propertyModels will be a collection containing all the translation 
        // property of the currently looping locale. So, we'll reduce the collection to a 
        // single collection containing just the properties and their translated value.
        $properties = $propertyModels->reduce(function ($prev, $propertyModel) {
            $prev->put($propertyModel->property, $propertyModel->property_value);

            return $prev;
        }, collect());

        // Finally we add the locale_id, just in case the frontend needs to know
        // it to group the localizations.
        $properties->put('locale_id', $localeId);

        return $properties;
    }

    /**
     * Returns the collection of all the translations in a format suitable for
     * presentation.
     * 
     * @param \Illuminate\Support\Collection $translations
     * @return \Illuminate\Support\Collection
     */
    public function getPropertyTranslationsAttribute()
    {
        $translations = $this->translations->groupBy('locale_id');

        $translations = $translations->map(function ($propertiesOfALocale, $localeId) {
            return $this->getPropertiesOfLocale($propertiesOfALocale, $localeId);
        });
        return $translations->values();
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQuery()
    {
        return parent::newQuery()->with('translations');
    }
}