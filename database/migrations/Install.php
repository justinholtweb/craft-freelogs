<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Freelog\Services\Digest;

/**
 * Freelog's one table: the error digest's marker and per-log read positions. The same shape
 * Freelog 5.1 created, so an upgraded site keeps its positions.
 */
class Install extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(Digest::TABLE)) {
            return;
        }

        Schema::create(Digest::TABLE, function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('handle', 64);
            // The period last claimed: `2026-10-09` or `2026-W41`. Null until the first run.
            $table->string('period', 32)->nullable();
            // JSON: per log file, the byte position the last digest read up to. Null until the
            // first digest is recorded.
            $table->mediumText('offsets')->nullable();
            $table->dateTime('lastRunAt')->nullable();
            $table->dateTime('lastSentAt')->nullable();
            $table->string('lastResult', 32)->nullable();
            $table->integer('lastCount')->default(0);
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
            $table->unique(['handle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Digest::TABLE);
    }
}
