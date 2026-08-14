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
     * What the first model pass produced, kept because the second pass is now a different job.
     *
     * Summarising used to hold this in a local variable between two calls in one method. Split
     * across a chain, the ideas have to cross a job boundary, and a column is where this
     * application already crosses one: the transcript does the same thing for the same reason.
     *
     * It buys the retry the same economy the transcript buys it. A row that already holds ideas
     * skips the pass that produced them, so an attempt that failed while writing the summary
     * costs one model call to repeat rather than two, and the second pass reads exactly the
     * ideas the first one wrote rather than whatever the model says about the transcript today.
     *
     * Beside the transcript in every other sense too. It is derived from other people's speech,
     * so it is covered by the same retention window and summaries:prune deletes it with the row;
     * it is deliberately not sent to the page, which reads the outline; and it is nullable
     * because most of a row's life is before or without it.
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
            $table->dropColumn('ideas');
        });
    }
};
