<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->uuid('musicbrainz_id')->nullable()->unique();
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->uuid('musicbrainz_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['musicbrainz_id']);
            $table->dropColumn('musicbrainz_id');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropUnique(['musicbrainz_id']);
            $table->dropColumn('musicbrainz_id');
        });
    }
};
