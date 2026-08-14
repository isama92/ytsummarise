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
     * The two columns summarising needs now that it is a chain of five jobs rather than one.
     *
     * Together because they arrive for the same underlying reason: work that used to happen inside
     * one method, holding what it needed in local variables, now happens across five jobs that
     * share nothing but this row. Anything the next step needs has to be written down.
     *
     * ideas - what the first model pass produced, kept because the second pass is a different job.
     *
     * A column is where this application already crosses a job boundary, the transcript doing the
     * same thing for the same reason, and it buys the retry the same economy. A row that already
     * holds ideas skips the pass that produced them, so an attempt that failed while writing the
     * summary costs one model call to repeat rather than two, and the second pass reads exactly
     * the ideas the first one wrote rather than whatever the model says about the transcript
     * today.
     *
     * Beside the transcript in every other sense too. It is derived from other people's speech, so
     * it is covered by the same retention window and summaries:prune deletes it with the row; it
     * is deliberately not sent to the page, which reads the outline; and it is nullable because
     * most of a row's life is before or without it.
     *
     * claim - which attempt owns this row, as a token rather than as a moment.
     *
     * started_at did both jobs until now: it recorded when work began and it identified the
     * attempt, because every step of a chain writes conditionally on the attempt it belongs to.
     * The second job was the one it could not quite do. The column is a timestamp(0), and
     * Laravel's query grammar binds dates as 'Y-m-d H:i:s' on both the write and the comparison,
     * so two attempts on one row inside the same wall-clock second are indistinguishable and the
     * older one's steps would happily write over the newer one's work.
     *
     * Raising the column's precision does not fix it, because the truncation is in the grammar
     * rather than in the column. A value that is not a date does.
     *
     * started_at keeps the job it is good at: it is what the page counts up from and what tells a
     * queued attempt from a running one. Whatever clears it for a retry has to clear this too, or
     * the row is left claimed by an attempt that no longer exists.
     */
    public function up(): void
    {
        Schema::table('summaries', function (Blueprint $table): void {
            $table->longText('ideas')->nullable()->after('transcript_language');
            $table->ulid('claim')->nullable()->after('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table): void {
            $table->dropColumn(['ideas', 'claim']);
        });
    }
};
