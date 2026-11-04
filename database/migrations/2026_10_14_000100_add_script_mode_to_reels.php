<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skill roteiro-reels-audiense (14/10): por padrão o roteiro é a ESTRUTURA com
 * ideias do que falar; o script completo só quando o criador pede.
 * Os roteiros que já existiam foram gerados como texto corrido, então ficam "script".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->string('script_mode', 12)->default('estrutura')->after('structure');
        });

        DB::table('reels')->whereNotNull('script_blocks')->update(['script_mode' => 'script']);
    }

    public function down(): void
    {
        Schema::table('reels', fn (Blueprint $t) => $t->dropColumn('script_mode'));
    }
};
