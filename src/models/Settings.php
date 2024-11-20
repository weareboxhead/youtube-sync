<?php

namespace boxhead\youtubesync\models;

use craft\base\Model;

/**
 * YouTubeSync Settings Model
 *
 * This is a model used to define the plugin's settings.
 *
 * Models are containers for data. Just about every time information is passed
 * between services, controllers, and templates in Craft, it’s passed via a model.
 */

class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * Some field model attribute
     *
     * @var string
     */
    public string $apiKey = '';
    public string $channelId = '';
    public string $sectionId = '';
    public string $entryTypeId = '';
    public string $youtubePlaylistsCategoryGroupId = '';
    public string $ignoreVideosOlderThan = '12';

    // Public Methods
    // =========================================================================

    /**
     * Returns the validation rules for attributes.
     *
     * Validation rules are used by [[validate()]] to check if attribute values are valid.
     * Child classes may override this method to declare different validation rules.
     *
     * More info: http://www.yiiframework.com/doc-2.0/guide-input-validation.html
     *
     * @return array
     */
    // public function rules()
    protected function defineRules(): array
    {
        return [
            ['apiKey', 'required'],
            ['channelId', 'required'],
            ['sectionId', 'required'],
            ['entryTypeId', 'required'],
            ['youtubePlaylistsCategoryGroupId', 'required'],
            ['ignoreVideosOlderThan', 'required'],
        ];
    }
}
