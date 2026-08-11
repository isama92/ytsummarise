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
             * Null until the job has something to write. Nothing distinguishes "not
             * summarised yet" from "summarised as nothing", because status already does.
             */
            $table->text('body')->nullable();

            /*
             * When the attempt currently in flight was asked for, which is what the page
             * counts up from while it waits and what decides when a summary has been
             * pending long enough to write off.
             *
             * Not created_at: a row outlives its attempts. Retrying a summary that failed
             * starts a new clock, while somebody joining a job already running has to see
             * the time the person before them has already waited, not zero.
             */
            $table->timestamp('requested_at');

            $table->timestamps();

            /*
             * The one query the expiry command runs.
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
