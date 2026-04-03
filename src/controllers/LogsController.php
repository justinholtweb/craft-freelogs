<?php

namespace justinholtweb\freelog\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\freelog\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

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
     * Display the log file list / main dashboard.
     */
    public function actionIndex(): Response
    {
        $logService = Plugin::getInstance()->logService;
        $files = $logService->getLogFiles();

        // Format file sizes for display
        foreach ($files as &$file) {
            $file['sizeFormatted'] = $logService->formatBytes($file['size']);
            $file['modifiedFormatted'] = Craft::$app->getFormatter()->asDatetime($file['modified']);
        }

        return $this->renderTemplate('freelog/index', [
            'files' => $files,
        ]);
    }

    /**
     * View a specific log file with search/filter.
     */
    public function actionView(): Response
    {
        $request = Craft::$app->getRequest();
        $filename = $request->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $logService = Plugin::getInstance()->logService;
        $filepath = $logService->resolveFilePath($filename);

        if ($filepath === null) {
            throw new NotFoundHttpException('Log file not found.');
        }

        $search = $request->getQueryParam('search', '');
        $level = $request->getQueryParam('level', '');
        $page = (int) $request->getQueryParam('page', 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $result = $logService->getLogEntries($filename, $search ?: null, $level ?: null, $limit, $offset);
        $totalPages = (int) ceil($result['total'] / $limit);

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
     * Download a log file.
     */
    public function actionDownload(): Response
    {
        $filename = Craft::$app->getRequest()->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $logService = Plugin::getInstance()->logService;
        $filepath = $logService->resolveFilePath($filename);

        if ($filepath === null) {
            throw new NotFoundHttpException('Log file not found.');
        }

        return Craft::$app->getResponse()->sendFile($filepath, $filename);
    }

    /**
     * Clear a log file.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $filename = Craft::$app->getRequest()->getBodyParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $logService = Plugin::getInstance()->logService;

        if ($logService->clearLog($filename)) {
            Craft::$app->getSession()->setNotice("Log file \"{$filename}\" cleared.");
        } else {
            Craft::$app->getSession()->setError("Could not clear \"{$filename}\".");
        }

        return $this->redirect('freelog');
    }

    /**
     * Tail endpoint for AJAX polling.
     */
    public function actionTail(): Response
    {
        $request = Craft::$app->getRequest();
        $filename = $request->getQueryParam('file');

        if (!$filename) {
            throw new NotFoundHttpException('Log file not specified.');
        }

        $logService = Plugin::getInstance()->logService;
        $content = $logService->getTail($filename);

        return $this->asJson([
            'content' => $content,
        ]);
    }
}
