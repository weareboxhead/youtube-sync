<?php
/**
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\utilities;

use Craft;
use craft\base\Utility;

class SyncUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('youtube-sync', 'YouTube Sync');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'youtube-sync';
    }

    /**
     * @inheritdoc
     */
    public static function iconPath(): ?string
    {
        $iconPath = Craft::getAlias('@vendor/boxhead/youtube-sync/src/icon-mask.svg');

        if (!is_string($iconPath)) {
            return null;
        }

        return $iconPath;
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('youtube-sync/_utility', [
            'actions' => self::getActions(),
        ]);
    }

    /**
     * Returns available actions.
     */
    public static function getActions(bool $showAll = false): array
    {
        $actions = [];

        $actions[] = [
            'id' => 'sync',
            'label' => Craft::t('youtube-sync', Craft::t('youtube-sync', 'Sync Now')),
            'instructions' => Craft::t('youtube-sync', 'Run the YouTube sync operation now.'),
        ];

        return $actions;
    }
}
