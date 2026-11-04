<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rodadas de pesquisa de assuntos virais
        Schema::create('research_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 500);                  // recorte da rodada
            $table->text('summary')->nullable();           // data, recorte, o que não foi verificado
            $table->json('result')->nullable();            // mapa, top 5, headlines, momento, fontes
            $table->json('youtube')->nullable();           // dados coletados no YouTube
            $table->unsignedBigInteger('reel_id')->nullable();
            $table->timestamps();
        });

        // Banco de referências: ideias do usuário + sugestões da IA
        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 10)->default('user'); // user | ai
            $table->string('title', 500);                   // tema / assunto
            $table->text('notes')->nullable();              // anotações (texto do usuário, nunca reescrito)
            $table->string('source', 500)->nullable();
            $table->string('category', 80)->nullable();     // universal | nicho | momento
            $table->string('type', 120)->nullable();        // desejo, dor, medo, crença, hábito, item, pessoa, obra
            $table->string('emotion', 120)->nullable();
            $table->text('evidence')->nullable();
            $table->json('links')->nullable();              // [{title,url,views}]
            $table->string('validity', 120)->nullable();    // atemporal | prazo
            $table->string('status', 20)->default('ideia'); // ideia | roteirizado | descartado
            $table->foreignId('research_round_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'origin', 'status']);
        });

        // Referências virais de outros perfis (engenharia reversa)
        Schema::create('viral_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1000)->nullable();
            $table->string('platform', 40)->nullable();
            $table->string('creator')->nullable();
            $table->string('headline', 1000);
            $table->string('views', 60)->nullable();
            $table->text('notes')->nullable();
            $table->string('template', 40)->nullable();
            $table->json('analysis')->nullable();
            $table->timestamps();
        });

        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('idea_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('theme');
            $table->text('notes')->nullable();
            $table->string('format', 20)->default('reels');
            $table->string('headline', 1000)->nullable();
            $table->json('headlines')->nullable();
            $table->json('research')->nullable();
            $table->foreignId('research_round_id')->nullable()->constrained()->nullOnDelete();
            $table->string('structure', 20)->nullable();
            $table->json('script_blocks')->nullable();
            $table->longText('script_clean')->nullable();
            $table->json('omitted')->nullable();
            $table->json('checks')->nullable();
            $table->unsignedSmallInteger('est_seconds')->nullable();
            $table->unsignedSmallInteger('word_count')->nullable();
            $table->text('reading_note')->nullable();
            $table->string('status', 20)->default('rascunho'); // rascunho|gerando|roteirizado|gravado|editado|postado|descartado
            $table->json('pipeline')->nullable();
            $table->date('planned_for')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->string('post_url', 1000)->nullable();
            $table->json('metrics')->nullable();
            $table->text('production_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('research_rounds', function (Blueprint $table) {
            $table->foreign('reel_id')->references('id')->on('reels')->nullOnDelete();
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 40);
            $table->string('status', 20)->default('queued'); // queued | running | done | error
            $table->foreignId('reel_id')->nullable()->constrained()->nullOnDelete();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('step', 40)->nullable();
            $table->text('log')->nullable();
            $table->text('error')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::table('research_rounds', fn (Blueprint $t) => $t->dropForeign(['reel_id']));
        Schema::dropIfExists('reels');
        Schema::dropIfExists('viral_refs');
        Schema::dropIfExists('ideas');
        Schema::dropIfExists('research_rounds');
    }
};
