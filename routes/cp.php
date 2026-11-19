<?php

declare(strict_types=1);

use CraftCms\Cms\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Freelog\Http\Controllers\DigestController;
use JustinHoltWeb\Freelog\Http\Controllers\LogsController;
use JustinHoltWeb\Freelog\Plugin;

use function CraftCms\Cms\cp_url;

// The CP group already applies `web`, `auth`, `can:accessCp` and the cpTrigger prefix. Logs hold
// request data, email addresses and stack traces, so every route also needs Freelog's own
// permission (and access to the plugin, which the sidebar asks for too).

Route::middleware(['can:accessPlugin-freelog', 'can:'.Plugin::PERMISSION_VIEW])->group(function () {
    Route::get('freelog', [LogsController::class, 'index'])->name('freelog');
    Route::get('freelog/view', [LogsController::class, 'view'])->name('freelog.view');
    Route::get('freelog/download', [LogsController::class, 'download'])->name('freelog.download');
    Route::get('freelog/tail', [LogsController::class, 'tail'])->name('freelog.tail');
    Route::post('freelog/clear', [LogsController::class, 'clear'])
        ->middleware('can:'.Plugin::PERMISSION_CLEAR)
        ->name('freelog.clear');
});

// The digest chooses who receives log contents by email: admins only.
Route::middleware(RequireAdmin::class)->group(function () {
    Route::get('freelog/digest', [DigestController::class, 'index'])->name('freelog.digest');
    Route::post('freelog/digest/test', [DigestController::class, 'sendTest'])->name('freelog.digest.test');
    // Freelog 5's settings screen; its digest emails link here.
    Route::get('freelog/settings', fn () => redirect(cp_url('settings/plugins/freelog')))->name('freelog.settings');
});
