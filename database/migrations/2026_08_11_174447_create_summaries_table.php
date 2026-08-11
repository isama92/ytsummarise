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

            $table->timestamps();
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
