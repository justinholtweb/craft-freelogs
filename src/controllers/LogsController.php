<?php

namespace justinholtweb\freelog\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\freelog\Plugin;
use justinholtweb\freelog\services\LogService;
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Logs controller.
 */
class LogsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('freelog:access');

        return true;
    }

    /**
     * Displays the log file list.
     */
    public function actionIndex(): Response
    {
        $logService = $this->logService();
        $formatter = Craft::$app->getFormatter();
        $files = $logService->getLogFiles();

        foreach ($files as &$file) {
            $file['sizeFormatted'] = $logService->formatBytes($file['size']);
            $file['modifiedFormatted'] = $formatter->asDatetime($file['modified']);
        }
        unset($file);

        return $this->renderTemplate('freelog/index', [
            'files' => $files,
        ]);
    }

    /**
     * Displays a specific log file with search and filter.
     */
    public function actionView(): Response
    {
        $filename = $this->request->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $logService = $this->logService();

        if ($logService->resolveFilePath($filename) === null) {
            throw new NotFoundHttpException('Log file not found.');
        }

        $search = $this->request->getQueryParam('search', '');
        $level = $this->request->getQueryParam('level', '');
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $result = $logService->getLogEntries($filename, $search ?: null, $level ?: null, $limit, $offset);
        $totalPages = (int)ceil($result['total'] / $limit);

        $files = $logService->getLogFiles();
        usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->renderTemplate('freelog/view', [
            'filename' => $filename,
            'entries' => $result['entries'],
            'total' => $result['total'],
            'search' => $search,
            'level' => $level,
            'page' => $page,
            'totalPages' => $totalPages,
            'limit' => $limit,
            'files' => $files,
        ]);
    }

    /**
     * Downloads a log file.
     */
    public function actionDownload(): Response
    {
        $filename = $this->request->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $filepath = $this->logService()->resolveFilePath($filename);

        if ($filepath === null) {
            throw new NotFoundHttpException('Log file not found.');
        }

        return $this->response->sendFile($filepath, basename($filename));
    }

    /**
     * Clears a log file.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $filename = $this->request->getBodyParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $session = Craft::$app->getSession();

        if ($this->logService()->clearLog($filename)) {
            $session->setNotice("Log file \"$filename\" cleared.");
        } else {
            $session->setError("Could not clear \"$filename\".");
        }

        return $this->redirect('freelog');
    }

    /**
     * Tail endpoint for AJAX polling.
     */
    public function actionTail(): Response
    {
        $this->requireAcceptsJson();

        $filename = $this->request->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        return $this->asJson([
            'content' => $this->logService()->getTail($filename),
        ]);
    }

    /**
     * Returns the log service.
     *
     * @throws InvalidConfigException if the plugin instance is unavailable.
     */
    private function logService(): LogService
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new InvalidConfigException('The Freelog plugin is not available.');
        }

        return $plugin->getLogService();
    }
}
