<?php

/**
 * @link      https://boxhead.io
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\jobs;

use craft\queue\BaseJob;
use boxhead\youtubesync\YouTubeSync;

class YouTubeCreateUpdateEntryJob extends BaseJob
{
    private $video;
    private $entryId;
    private $playlistCategoryId;

    /**
     * @inheritdoc
     */
    public function __construct($video, $entryId = null, $playlistCategoryId = null)
    {
        $this->video = $video;
        $this->entryId = $entryId;
        $this->playlistCategoryId = $playlistCategoryId;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Create or Update Craft entry correlating to this YouTube video
        YouTubeSync::$plugin->youTubeSyncService->parseVideo($this->video, $this->entryId, $this->playlistCategoryId);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return \Craft::t('app', 'Syncing YouTube video - "' . $this->video->snippet->title . '"');
    }
}
