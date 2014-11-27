<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tracker;

use Piwik\Cache\StaticCache;
use Piwik\Settings\Storage;
use Piwik\Tracker;

/**
 * Loads settings from tracker cache instead of database. If not yet present in tracker cache will cache it.
 */
class SettingsStorage extends Storage
{
    private static $cachedSettings; // we need a further cache otherwise we'd read values cache file over and over again
    private $trackerKey = 'settingsStorage';

    protected function loadSettings()
    {
        $trackerCache = $this->getTrackerCache();
        $settings = null;

        if (array_key_exists($this->trackerKey, $trackerCache)) {
            $allSettings = $trackerCache[$this->trackerKey];

            if (is_array($allSettings) && array_key_exists($this->getOptionKey(), $allSettings)) {
                $settings = $allSettings[$this->getOptionKey()];
            }
        }

        if (is_null($settings)) {
            $settings = parent::loadSettings();

            $trackerCache = Cache::getCacheGeneral(); // we need to get latest tracker cache in case it was updated somewhere else

            if (!array_key_exists($this->trackerKey, $trackerCache)) {
                $trackerCache[$this->trackerKey] = array();
            }

            $trackerCache[$this->trackerKey][$this->getOptionKey()] = $settings;
            $this->setTrackerCache($trackerCache);
        }

        return $settings;
    }

    private function getTrackerCache()
    {
        if (!is_null(self::$cachedSettings)) {
            return self::$cachedSettings;
        }

        return Cache::getCacheGeneral();
    }

    private function setTrackerCache($trackerCache)
    {
        self::$cachedSettings = $trackerCache;

        return Cache::setCacheGeneral($trackerCache);
    }

    public function save()
    {
        parent::save();
        self::clearCache();
    }

    public static function clearCache()
    {
        self::$cachedSettings = null;
        Cache::clearCacheGeneral();
    }

}
