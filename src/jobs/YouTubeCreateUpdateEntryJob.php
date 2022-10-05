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

    /**
     * @inheritdoc
     */
    public function __construct($video, $entryId = null)
    {
        $this->video = $video;
        $this->entryId = $entryId;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Create or Update Craft entry correlating to this YouTube video
        YouTubeSync::$plugin->youTubeSyncService->parseVideo($this->video, $this->entryId);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return \Craft::t('app', 'Syncing YouTube video - "' . $this->video->snippet->title . '"');
    }
}
