<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('summaries', function (Blueprint $table): void {
            $table->id();

            /*
             * What a summary is addressed by. The video id is public knowledge, sitting
             * in the YouTube url everyone can see, so keying the page on it would let
             * anyone walk the parameter and read every summary anybody has ever asked
             * for. A uuid carries nothing about the video and cannot be enumerated.
             *
             * The primary key stays an integer: this column is the public handle, not
             * the identity the rest of the schema would join on.
             */
            $table->uuid('uuid')->unique();

            /*
             * A YouTube id is always eleven characters of [A-Za-z0-9_-], so the column is
             * sized to it rather than left as a loose string. Unique, because a summary
             * belongs to a video and not to whoever asked for it: the second person to
             * request the same video is answered from the first person's row instead of
             * paying for the model call again.
             */
            $table->string('video_id', 11)->unique();

            $table->string('status');

            /*
             * The video's own title, looked up by the job along with the summary and
             * written with it, so the heading and the text arrive together.
             *
             * Nullable because it comes from somewhere else. A video whose owner disabled
             * embedding exists but will not be named by the lookup, and it is worth
             * summarising anyway; the page simply leaves the heading out.
             */
            $table->string('title')->nullable();

            /*
             * What was summarised, as plain text with the caption timings taken out.
             *
             * Kept rather than discarded because the two expensive steps fail
             * independently. Fetching this is the step YouTube can refuse; the model call
             * is the step that can come back unusable. Keeping the transcript means a
             * retry after the second kind of failure re-runs only the model, offline, over
             * exactly the text the first attempt saw.
             *
             * Other people's words, so it is worth saying out loud that this is the column
             * with a retention question attached. Nothing prunes it today.
             *
             * Null for every attempt that failed before reaching one, which includes every
             * video that has no captions at all.
             */
            $table->longText('transcript')->nullable();

            /*
             * What language the transcript above is in, as a primary subtag: `en`, `nl`, `pt`.
             *
             * Written with it and null with it, because it cannot be recovered from the text by
             * anything cheaper than asking a model. It decides whether a summary needs
             * translating afterwards, so a reused transcript without it would be summarised as
             * though it were English.
             *
             * Short, but not eleven-characters short: a primary subtag is two or three letters
             * today and this is not a column worth being clever about.
             */
            $table->string('transcript_language', 12)->nullable();

            /*
             * The summary itself, as an object rather than as prose: a headline, the main
             * points and the takeaways, each of them separately addressable so the page can
             * lay them out instead of printing paragraphs.
             *
             * One column and not two, holding both language versions. A video that is not
             * in English is summarised in its own language and that summary is then
             * translated, and the two belong to each other - they are one answer about one
             * video, written twice. Splitting them into columns would make every read
             * assemble them again, and a separator inside one text column would be a parser
             * nobody wants to own. The english key is null when there was nothing to
             * translate.
             *
             * jsonb rather than json: Postgres parses it once on write instead of on every
             * read, and it is the type that can be indexed if anything ever needs to look
             * inside. Sqlite, which the tests run on, has neither and stores text either way.
             *
             * Null until the job has something to write. Nothing distinguishes "not
             * summarised yet" from "summarised as nothing", because status already does.
             */
            $table->jsonb('outline')->nullable();

            /*
             * When the attempt currently in flight was asked for. What the page counts up
             * from while it waits, and what decides when an attempt has been pending long
             * enough to give up on.
             *
             * Not created_at: a row outlives its attempts. Set again every time one starts,
             * so a video summarised yesterday and asked for again a minute ago has a minute
             * on the clock rather than a day, while somebody joining a job already running
             * sees the time the person before them has already waited rather than zero.
             */
            $table->timestamp('requested_at');

            /*
             * When a worker actually began, which is a different question from when it was
             * asked for: a job can sit in a queue behind another for as long as that one
             * takes. The page says "Queued" or "Processing" on the strength of this.
             *
             * Null means no worker has started, and setting it is how a job claims the row:
             * the update is conditional on it still being null, so of two jobs for the same
             * video exactly one can win and the other returns having done nothing. That is
             * a guarantee from the database rather than from a lock's expiry.
             *
             * Cleared whenever an attempt restarts, or the row is claimed for an attempt
             * nobody is working on and every job for it returns having done nothing.
             */
            $table->timestamp('started_at')->nullable();

            /*
             * Why a failed attempt failed, as one of the codes in App\Enums\SummaryError.
             *
             * A code and not a sentence: the wording lives in lang/en/summaries.php, so it
             * can be rewritten without a migration and without older rows contradicting
             * the current release. Status says an attempt produced nothing; this says
             * whether that is worth trying again.
             *
             * Nullable and null for every row that has not failed. Cleared when an attempt
             * is retried and when one succeeds, so a reason here always belongs to the
             * attempt the row is currently showing.
             */
            $table->string('error')->nullable();

            $table->timestamps();

            /*
             * The one query the expiry command runs: pending attempts old enough to give
             * up on. started_at is deliberately not in here - nothing searches by it, the
             * job reads it one row at a time by primary key.
             */
            $table->index(['status', 'requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
