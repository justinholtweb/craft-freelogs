<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Http\Controllers;

use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\I18N;
use CraftCms\Cms\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\Freelog\Plugin;
use JustinHoltWeb\Freelog\Services\LogService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\t;

/**
 * The log viewer: the file list, one file's entries, download, clear and tail.
 *
 * Every route is behind `freelog:access` (see `routes/cp.php`), and clearing behind
 * `freelog:clear` too. A requested name only ever reaches a file through
 * {@see LogService::resolveFilePath()}, which refuses anything outside `storage/logs`.
 */
class LogsController
{
    use RespondsWithFlash;

    /** Entries per page. */
    public const int PAGE_SIZE = 50;

    public function __construct(
        private readonly LogService $logs,
    ) {}

    public function index(): CpScreenResponse
    {
        $formatter = I18N::getFormatter();
        $files = $this->logs->getLogFiles();

        foreach ($files as &$file) {
            $file['sizeFormatted'] = $this->logs->formatBytes($file['size']);
            $file['modifiedFormatted'] = $formatter->asDateTime($file['modified'], 'short');
        }
        unset($file);

        return new CpScreenResponse()
            ->title(t('Freelog', category: 'freelog'))
            ->contentTemplate('freelog/index', [
                'files' => $files,
                'canClear' => Gate::check(Plugin::PERMISSION_CLEAR),
            ]);
    }

    public function view(Request $request): CpScreenResponse
    {
        $filename = $this->requestedFile($request->query('file'));
        $search = trim((string) $request->query('search', ''));
        $level = strtolower(trim((string) $request->query('level', '')));
        $page = max(1, (int) $request->query('page', '1'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $result = $this->logs->getLogEntries($filename, $search ?: null, $level ?: null, self::PAGE_SIZE, $offset);

        $files = $this->logs->getLogFiles();
        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return new CpScreenResponse()
            ->title($filename)
            ->docTitle($filename.' – Freelog')
            ->crumbs([
                new ActionItem()->label(t('Freelog', category: 'freelog'))->href(Url::cpUrl('freelog')),
            ])
            ->contentTemplate('freelog/view', [
                'filename' => $filename,
                'entries' => $result['entries'],
                'total' => $result['total'],
                'search' => $search,
                'level' => $level,
                'page' => $page,
                'totalPages' => (int) ceil($result['total'] / self::PAGE_SIZE),
                'limit' => self::PAGE_SIZE,
                'files' => $files,
                'canClear' => Gate::check(Plugin::PERMISSION_CLEAR),
            ]);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $filename = $this->requestedFile($request->query('file'));
        $path = (string) $this->logs->resolveFilePath($filename);

        // Streamed from disk; the name in the header is the file's own, not what was asked for.
        return response()->download($path, basename($path), [
            'Content-Type' => $this->logs->isCompressed($path) ? 'application/gzip' : 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function clear(Request $request): Response
    {
        $filename = $this->requestedFile($request->input('file'));
        $path = (string) $this->logs->resolveFilePath($filename);

        // The name in the notice is the file's own, not what was posted.
        $name = basename($path);

        if (! $this->logs->clearLog($name)) {
            return $this->asFailure(t('Couldn’t clear {file}.', ['file' => $name], category: 'freelog'));
        }

        return $this->asSuccess(t('Cleared {file}.', ['file' => $name], category: 'freelog'), redirect: Url::cpUrl('freelog'));
    }

    public function tail(Request $request): JsonResponse
    {
        $filename = $this->requestedFile($request->query('file'));

        return response()->json([
            'content' => $this->logs->getTail($filename),
        ]);
    }

    /**
     * The requested name, if it resolves to a log in `storage/logs`. Anything else — no name, a
     * path, `../`, a symlink out, a file without a log extension — is a 404, the same answer as
     * a log that doesn't exist, so the response says nothing about what is on the server.
     */
    private function requestedFile(mixed $file): string
    {
        if (! is_string($file) || $file === '' || $this->logs->resolveFilePath($file) === null) {
            abort(404, t('Log file not found.', category: 'freelog'));
        }

        return basename($file);
    }
}
