<?php
/**
 * YouTube Sync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the YouTube Data API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\youtubesync;

use boxhead\youtubesync\services\YouTubeSyncService as YouTubeSyncServiceService;
use boxhead\youtubesync\models\Settings;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\web\UrlManager;
use craft\services\Elements;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\models\FieldGroup;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\elements\Entry;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Boxhead
 * @package   YouTubeSync
 * @since     1.0.0
 *
 * @property  youTubeSyncServiceService $youTubeSyncService
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class YouTubeSync extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * YouTubeSync::$plugin
     *
     * @var YouTubeSync
     */
    public static $plugin;
    public $hasCpSettings = true;

    // Public Methods
    // =========================================================================
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register our site routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['youtube-sync/sync']   = 'youtube-sync/default/sync-with-remote';
                $event->rules['youtube-sync/update'] = 'youtube-sync/default/update-local-data';
            }
        );

        // Register our elements
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
            }
        );

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                    $this->buildFieldSectionStructure();
                }
            }
        );

        /**
         * Logging in Craft involves using one of the following methods:
         *
         * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
         * Craft::info(): record a message that conveys some useful information.
         * Craft::warning(): record a warning message that indicates something unexpected has happened.
         * Craft::error(): record a fatal error that should be investigated as soon as possible.
         *
         * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
         *
         * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
         * the category to the method (prefixed with the fully qualified class name) where the constant appears.
         *
         * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
         * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
         *
         * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
         */
        Craft::info(
            Craft::t(
                'church-suite',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

    protected function buildFieldSectionStructure()
    {
        // Create the Small Groups Field Group
        Craft::info('Creating the YouTube Videos Field Group.', __METHOD__);

        $fieldsService = Craft::$app->getFields();

        $fieldGroup = new FieldGroup();
        $fieldGroup->name = 'YouTube Videos';

        if (!$fieldsService->saveGroup($fieldGroup)) {
            Craft::error('Could not save the YouTube Videos field group.', __METHOD__);

            return false;
        }

        $fieldGroupId = $fieldGroup->id;

        // Create the Basic Fields
        Craft::info('Creating the basic YouTube Video Fields.', __METHOD__);

        $basicFields = [
            [
                'handle'    => 'ytVideoId',
                'name'      => 'YouTube Video Id',
                'type'      => 'craft\fields\PlainText'
            ],
            [
                'handle'    => 'ytDescription',
                'name'      => 'YouTube Video Description',
                'type'      => 'craft\fields\PlainText',
                'multiline' => true
            ],
            [
                'handle'    => 'ytDuration',
                'name'      => 'YouTube Video Duration',
                'type'      => 'craft\fields\PlainText'
            ],
            [
                'handle'    => 'ytImageMaxRes',
                'name'      => 'YouTube Video Image Max Resolution',
                'type'      => 'craft\fields\PlainText'
            ]
        ];

        $youtubeVideosEntryLayoutIds = [];

        foreach ($basicFields as $basicField) {
            Craft::info('Creating the ' . $basicField['name'] . ' field.', __METHOD__);

            $field = $fieldsService->createField([
                'groupId' => $fieldGroupId,
                'name' => $basicField['name'],
                'handle' => $basicField['handle'],
                'type' => $basicField['type']
            ]);

            if (!$fieldsService->saveField($field)) {
                Craft::error('Could not save the ' . $basicField['name'] . ' field.', __METHOD__);

                return false;
            }

            $youtubeVideosEntryLayoutIds[] = $field->id;
        }

        // Create the YouTube Playlists Field Group
        Craft::info('Creating the YouTube Playlists Field Group.', __METHOD__);

        $fieldsService = Craft::$app->getFields();

        $playlistsFieldGroup = new FieldGroup();
        $playlistsFieldGroup->name = 'YouTube Playlists';

        if (!$fieldsService->saveGroup($playlistsFieldGroup)) {
            Craft::error('Could not save the Sites field group.', __METHOD__);

            return false;
        }

        $playlistsFieldGroupId = $playlistsFieldGroup->id;

        // Create the Basic Fields
        Craft::info('Creating the YouTube Playlist Fields.', __METHOD__);

        $playlistsFields = [
            [
                'handle'    => 'ytPlaylistId',
                'name'      => 'YouTube Playlist Id',
                'type'      => 'craft\fields\PlainText'
            ],
            [
                'handle'    => 'ytPlaylistDescription',
                'name'      => 'YouTube Playlist Description',
                'type'      => 'craft\fields\PlainText',
                'multiline' => true
            ],
            [
                'handle'    => 'ytPlaylistImageMaxRes',
                'name'      => 'YouTube Playlist Image Max Resolution',
                'type'      => 'craft\fields\PlainText'
            ]
        ];

        $playlistsEntryLayoutIds = [];

        foreach ($playlistsFields as $playlistsField) {
            Craft::info('Creating the ' . $playlistsField['name'] . ' field.', __METHOD__);

            $field = $fieldsService->createField([
                'groupId' => $playlistsFieldGroupId,
                'name' => $playlistsField['name'],
                'handle' => $playlistsField['handle'],
                'type' => $playlistsField['type']
            ]);

            if (!$fieldsService->saveField($field)) {
                Craft::error('Could not save the ' . $playlistsField['name'] . ' field.', __METHOD__);

                return false;
            }

            $playlistsEntryLayoutIds[] = $field->id;
        }

        // Create the Sites Field Layout
        Craft::info('Creating the YouTube Playlists Field Layout.', __METHOD__);

        $playlistsFieldLayout = $fieldsService->assembleLayout(['YouTube Playlists' => $playlistsEntryLayoutIds], []);

        if (!$playlistsFieldLayout) {
            Craft::error('Could not create the YouTube Playlists Field Layout', __METHOD__);

            return false;
        }

        $playlistsFieldLayout->type = Category::class;

        // Create the YouTube Playslists category group
        Craft::info('Creating the YouTube Playlists category group.', __METHOD__);

        $playlistsCategoryGroup = new CategoryGroup();

        $playlistsCategoryGroup->name = 'YouTube Playlists';
        $playlistsCategoryGroup->handle = 'ytPlaylists';

        // Site-specific settings
        $allSiteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings = new CategoryGroup_SiteSettings();
            $siteSettings->siteId = $site->id;
            $siteSettings->uriFormat = null;
            $siteSettings->template = null;
            $siteSettings->hasUrls = false;

            $allSiteSettings[$site->id] = $siteSettings;
        }

        $playlistsCategoryGroup->setSiteSettings($allSiteSettings);

        $playlistsCategoryGroup->setFieldLayout($playlistsFieldLayout);

        if (!Craft::$app->getCategories()->saveGroup($playlistsCategoryGroup)) {
            Craft::error('Could not create the YouTube Playlists category group.', __METHOD__);

            return false;
        }

        // Create the YouTube Playlists Categories field
        Craft::info('Creating the YouTube Playlists Categories field.', __METHOD__);

        $playlistsCategoriesField = $fieldsService->createField([
            'groupId'   => $fieldGroupId,
            'name'      => 'YouTube Playlists',
            'handle'    => 'ytPlaylists',
            'type'      => 'craft\fields\Categories',
            'settings'  => ['source' => 'group:' . $playlistsCategoryGroup->id]
        ]);

        if (!$fieldsService->saveField($playlistsCategoriesField)) {
            Craft::error('Could not save the YouTube Playlists Categories field.', __METHOD__);

            return false;
        }

        $youtubeVideosEntryLayoutIds[] = $playlistsCategoriesField->id;

        // Create the YouTube Videos Field Layout
        Craft::info('Creating the YouTube Videos Field Layout.', __METHOD__);

        $youtubeVideosEntryLayout = $fieldsService->assembleLayout(['YouTube Videos' => $youtubeVideosEntryLayoutIds], []);

        if (!$youtubeVideosEntryLayout) {
            Craft::error('Could not create the YouTube Videos Field Layout', __METHOD__);

            return false;
        }

        $youtubeVideosEntryLayout->type = Entry::class;

        // Create the Small Groups Channel
        Craft::info('Creating the Videos Channel.', __METHOD__);

        $youtubeVideosChannelSection                 = new Section();
        $youtubeVideosChannelSection->name             = 'YouTube Videos';
        $youtubeVideosChannelSection->handle           = 'youtubeVideos';
        $youtubeVideosChannelSection->type             = Section::TYPE_CHANNEL;
        $youtubeVideosChannelSection->enableVersioning = false;

        // Site-specific settings
        $allSiteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings = new Section_SiteSettings();
            $siteSettings->siteId = $site->id;
            $siteSettings->uriFormat = null;
            $siteSettings->template = null;
            $siteSettings->enabledByDefault = true;
            $siteSettings->hasUrls = false;

            $allSiteSettings[$site->id] = $siteSettings;
        }

        $youtubeVideosChannelSection->setSiteSettings($allSiteSettings);

        $sectionsService = Craft::$app->getSections();

        if (!$sectionsService->saveSection($youtubeVideosChannelSection)) {
            Craft::error('Could not create the YouTube Videos Channel.', __METHOD__);

            return false;
        }

        // Get the array of entry types for our new section
        $youtubeVideosEntryTypes = $sectionsService->getEntryTypesBySectionId($youtubeVideosChannelSection->id);

        // There will only be one so get that
        $youtubeVideosEntryType = $youtubeVideosEntryTypes[0];
        $youtubeVideosEntryType->setFieldLayout($youtubeVideosEntryLayout);

        if (!$sectionsService->saveEntryType($youtubeVideosEntryType)) {
            Craft::error('Could not update the YouTube Videos Channel Entry Type.', __METHOD__);

            return false;
        }

        // Save the settings based on the section and entry type we just created
        Craft::$app->getPlugins()->savePluginSettings($this, [
            'sectionId'                         => $youtubeVideosChannelSection->id,
            'entryTypeId'                       => $youtubeVideosEntryType->id,
            'youtubePlaylistsCategoryGroupId'   => $playlistsCategoryGroup->id
        ]);
    }



    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }


    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'youtube-sync/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }
}
