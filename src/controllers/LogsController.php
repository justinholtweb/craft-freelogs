<?php

namespace justinholtweb\freelog\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\freelog\Plugin;
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
        $logService = Plugin::getInstance()->getLogService();
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
        $request = Craft::$app->getRequest();
        $filename = $request->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $logService = Plugin::getInstance()->getLogService();

        if ($logService->resolveFilePath($filename) === null) {
            throw new NotFoundHttpException('Log file not found.');
        }

        $search = $request->getQueryParam('search', '');
        $level = $request->getQueryParam('level', '');
        $page = (int)$request->getQueryParam('page', 1);
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
        $filename = Craft::$app->getRequest()->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $filepath = Plugin::getInstance()->getLogService()->resolveFilePath($filename);

        if ($filepath === null) {
            throw new NotFoundHttpException('Log file not found.');
        }

        return Craft::$app->getResponse()->sendFile($filepath, $filename);
    }

    /**
     * Clears a log file.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $filename = Craft::$app->getRequest()->getBodyParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $session = Craft::$app->getSession();

        if (Plugin::getInstance()->getLogService()->clearLog($filename)) {
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

        $filename = Craft::$app->getRequest()->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        return $this->asJson([
            'content' => Plugin::getInstance()->getLogService()->getTail($filename),
        ]);
    }
}
