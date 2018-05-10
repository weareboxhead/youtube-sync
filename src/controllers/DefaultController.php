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

use boxhead\youtubesync\YouTubeSync;
use boxhead\youtubesync\tasks\YouTubeSyncTask as YouTubeSyncTaskTask;

use Craft;
use craft\web\Controller;

/**
 * Default Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Boxhead
 * @package   YouTubeSync
 * @since     1.0.0
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
    protected $allowAnonymous = ['sync-with-remote', 'update-local-data'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's SyncWithRemote action URL,
     * e.g.: actions/youtube-sync/sync-with-remote
     *
     * @return mixed
     */
    public function actionSyncWithRemote() {
        YouTubeSync::$plugin->youTubeSyncService->sync();

        $result = 'Syncing remote YouTube data';

        return $result;
    }

    /**
     * Handle a request going to our plugin's actionUpdateLocalData URL,
     * e.g.: actions/youtube-sync/default/update-local-data
     *
     * @return mixed
     */
    public function actionUpdateLocalData() {
        $tasks = Craft::$app->getTasks();

        if (!$tasks->areTasksPending(YouTubeSyncTaskTask::class)) {
            $tasks->createTask(YouTubeSyncTaskTask::class);
        }

        $result = 'Updating Local YouTube Data';

        return $result;
    }
}
