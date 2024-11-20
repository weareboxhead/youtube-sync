<?php

/**
 * @link      https://boxhead.io
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\controllers;

use boxhead\youtubesync\jobs\YouTubeSyncJob;
use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use craft\web\Response;
use craft\web\View;

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
    protected array|int|bool $allowAnonymous = ['sync'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's SyncWithRemote action URL,
     * e.g.: actions/youtube-sync/sync-with-remote
     *
     * @return mixed
     */
    public function actionSync(): Response
    {
        Queue::push(new YouTubeSyncJob());

        $message = 'Sync in progress.';

        return $this->getResponse($message);
    }

    /**
     * Returns a response.
     */
    private function getResponse(string $message, bool $success = true): Response
    {
        $request = Craft::$app->getRequest();

        // If front-end or JSON request
        if (Craft::$app->getView()->templateMode == View::TEMPLATE_MODE_SITE || $request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $success,
                'message' => Craft::t('youtube-sync', $message),
            ]);
        }

        if ($success) {
            Craft::$app->getSession()->setNotice(Craft::t('youtube-sync', $message));
        } else {
            Craft::$app->getSession()->setError(Craft::t('youtube-sync', $message));
        }

        return $this->redirectToPostedUrl();
    }
}
