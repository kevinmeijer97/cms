<?php

namespace Statamic\CP\Navigation;

use ArrayAccess;
use Statamic\Support\Arr;
use Statamic\Support\Str;

class UserNavConfig implements ArrayAccess
{
    protected $config;

    const ALLOWED_NAV_ITEM_ACTIONS = [
        '@create',   // create new item
        '@remove',   // hide item (only works if item is in its original section)
        '@modify',   // modify item (only works if item is in its original section)
        '@alias',    // alias into another section (can also modify item)
        '@move',     // move into another section (can also modify item)
        '@inherit',  // inherit item without modification (used for reordering purposes only, when none of the above apply)
    ];

    const ALLOWED_NAV_ITEM_MODIFICATIONS = [
        'display',
        'url',
        'route',
        'icon',
        'children',
    ];

    /**
     * Instantiate user nav config helper.
     *
     * @param  array  $userNavPreferences
     */
    public function __construct($userNavPreferences)
    {
        $this->config = $this->normalizeConfig($userNavPreferences);
    }

    /**
     * Instantiate user nav config helper.
     *
     * @param  array  $userNavPreferences
     * @return static
     */
    public static function normalize($userNavPreferences)
    {
        return new static($userNavPreferences);
    }

    /**
     * Get normalized config.
     *
     * @return array
     */
    public function get()
    {
        return $this->config;
    }

    /**
     * Normalize config.
     *
     * @param  array  $navConfig
     * @return array
     */
    protected function normalizeConfig($navConfig)
    {
        $navConfig = collect($navConfig);

        $normalized = collect()->put('reorder', $reorder = $navConfig->get('reorder', false));

        $sections = collect($navConfig->get('sections') ?? $navConfig->except('reorder'));

        $sections = $sections
            ->prepend($sections->pull('top_level') ?? '@inherit', 'top_level')
            ->map(fn ($config, $section) => $this->normalizeSectionConfig($config, $section))
            ->reject(fn ($config) => $config['action'] === '@inherit' && ! $reorder)
            ->map(fn ($config) => Arr::except($config, 'action'))
            ->all();

        $normalized->put('sections', $sections);

        $allowedKeys = ['reorder', 'sections'];

        return $normalized->only($allowedKeys)->all();
    }

    /**
     * Normalize section config.
     *
     * @param  mixed  $sectionConfig
     * @param  string  $sectionKey
     * @return array
     */
    protected function normalizeSectionConfig($sectionConfig, $sectionKey)
    {
        $sectionConfig = is_string($sectionConfig)
            ? collect(['action' => Str::ensureLeft($sectionConfig, '@')])
            : collect($sectionConfig);

        $normalized = collect();

        $normalized->put('action', $sectionConfig->get('action', false));

        $normalized->put('display', $sectionConfig->get('display', Str::modifyMultiple($sectionKey, ['deslugify', 'title'])));

        $normalized->put('reorder', $reorder = $sectionConfig->get('reorder', false));

        $items = collect($sectionConfig->get('items') ?? $sectionConfig->except([
            'reorder',
            'display',
        ]));

        $items = $items
            ->map(function ($config, $itemId) use ($sectionKey) {
                return $this->normalizeItemConfig($itemId, $config, $sectionKey);
            })
            ->filter()
            ->reject(fn ($config) => $config['action'] === '@inherit' && ! $reorder)
            ->all();

        $normalized->put('items', $items);

        $allowedKeys = ['action', 'reorder', 'display', 'items'];

        return $normalized->only($allowedKeys)->all();
    }

    /**
     * Normalize item config.
     *
     * @param  string  $itemId
     * @param  mixed  $itemConfig
     * @param  string  $sectionKey
     * @return array
     */
    protected function normalizeItemConfig($itemId, $itemConfig, $sectionKey)
    {
        $normalized = is_string($itemConfig)
            ? collect(['action' => Str::ensureLeft($itemConfig, '@')])
            : collect($itemConfig);

        $isModified = $this->itemIsModified($itemConfig);
        $isInOriginalSection = $this->itemIsInOriginalSection($itemId, $sectionKey);

        // Remove item when not properly using section-specific actions, to ensure the JS nav builder doesn't
        // do unexpected things. See comments on `ALLOWED_NAV_ITEM_ACTIONS` constant at top for details.
        if ($isInOriginalSection && in_array($normalized->get('action'), ['@move'])) {
            return null;
        } elseif (! $isInOriginalSection && in_array($normalized->get('action'), ['@remove', '@modify', '@inherit'])) {
            return null;
        }

        // If action is not set, determine the best default action.
        if (! in_array($normalized->get('action'), static::ALLOWED_NAV_ITEM_ACTIONS)) {
            if ($isModified && $isInOriginalSection) {
                $normalized->put('action', '@modify');
            } elseif ($isInOriginalSection) {
                $normalized->put('action', '@inherit');
            } else {
                $normalized->put('action', '@alias');
            }
        }

        $allowedKeys = array_merge(['action'], static::ALLOWED_NAV_ITEM_MODIFICATIONS);

        return $normalized->only($allowedKeys)->all();
    }

    /**
     * Determine if config is modifying a nav item.
     *
     * @param  array  $config
     * @return bool
     */
    protected function itemIsModified($config)
    {
        if (is_string($config) || ! $config) {
            return false;
        }

        $possibleModifications = array_merge(static::ALLOWED_NAV_ITEM_MODIFICATIONS);

        return collect($possibleModifications)
            ->intersect(array_keys($config))
            ->isNotEmpty();
    }

    /**
     * Determine if nav item is in original section.
     *
     * @param  string  $itemId
     * @param  string  $currentSectionKey
     * @return bool
     */
    protected function itemIsInOriginalSection($itemId, $currentSectionKey)
    {
        return Str::startsWith($itemId, "$currentSectionKey::");
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($key)
    {
        return $this->config[$key];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($key, $value)
    {
        throw new \Exception('Method offsetSet is not currently supported.');
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($key)
    {
        throw new \Exception('Method offsetExists is not currently supported.');
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($key)
    {
        throw new \Exception('Method offsetUnset is not currently supported.');
    }
}