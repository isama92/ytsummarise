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
     * Which attempt owns this row, as a token rather than as a moment.
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
            $table->ulid('claim')->nullable()->after('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table): void {
            $table->dropColumn('claim');
        });
    }
};
