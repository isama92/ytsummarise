<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The one queue table left, and the only one Redis does not replace.
     *
     * The jobs and job_batches tables went with the move to Horizon: queued work lives in
     * Redis now, and Horizon reads it from there. Failures deliberately did not follow.
     * queue.failed.driver is still database-uuids, so a summary that threw is a row in
     * Postgres rather than an entry Horizon trims after a week - it survives a Redis flush,
     * it survives Redis being rebuilt from nothing, and `queue:retry` still works on it.
     */
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
