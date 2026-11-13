<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les codes OTP sont désormais stockés hachés (colonne plus longue)
     * et le nombre d'essais est compté pour bloquer les attaques par force brute.
     */
    public function up(): void
    {
        // Les anciens codes en clair ne sont plus valides.
        DB::table('otp_codes')->delete();

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('code', 255)->change();
            $table->unsignedTinyInteger('attempts')->default(0)->after('type');
            $table->index('email');
        });
    }

    public function down(): void
    {
        DB::table('otp_codes')->delete();

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropColumn('attempts');
            $table->string('code', 4)->change();
        });
    }
};
