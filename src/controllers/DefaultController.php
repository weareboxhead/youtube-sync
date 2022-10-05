<?php

/**
 * @link      https://boxhead.io
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\controllers;

use boxhead\youtubesync\jobs\YouTubeSyncJob;
use craft\helpers\Queue;
use craft\web\Controller;

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
