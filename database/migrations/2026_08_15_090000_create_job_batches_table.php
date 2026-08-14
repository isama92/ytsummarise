<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Back, after being dropped with the jobs table when queued work moved to Redis. The
     * reasoning then was that nothing dispatched a batch and the key only pointed at somewhere
     * to record them; summarising is now a chain of five steps inside one named batch, which is
     * what puts the progress on Horizon's Batches tab, so there is somewhere to record again.
     *
     * In Postgres rather than Redis, and not by choice: queue.batching names a database
     * connection and nothing else. That turns out to suit it. A batch's row outlives the jobs
     * it counts, `queue:prune-batches` is what removes it rather than a Redis eviction, and a
     * Redis flush leaves a finished batch legible instead of erasing what ran.
     *
     * created_at and finished_at are integers, not timestamps. That is Laravel's own shape for
     * this table - they hold unix seconds - so anything ordering by them is ordering on an int
     * and `latest()` still does the right thing by accident rather than by design.
     */
    public function up(): void
    {
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();

            /*
             * The one column here that is read rather than written. Summarising names its
             * batches "Summarise <video code> (<summary id>)", which is what makes a batch
             * findable from a video somebody is asking about; see App\Actions\SummariseVideo.
             */
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
