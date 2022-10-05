<?php
/**
 * YouTubeSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the YouTube Data API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\youtubesync\controllers;

use craft\helpers\Queue;
use craft\web\Controller;
use boxhead\youtubesync\jobs\YouTubeSyncJob;

/**
 *
 * @author    Boxhead
 * @package   YouTubeSync
 */
class DefaultController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['sync-with-remote'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's SyncWithRemote action URL,
     * e.g.: actions/youtube-sync/sync-with-remote
     *
     * @return mixed
     */
    public function actionSyncWithRemote()
    {
        Queue::push(new YouTubeSyncJob());
    }
}
