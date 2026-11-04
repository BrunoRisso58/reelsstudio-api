<?php

namespace Tests\Feature;

use App\Ai\Brain;
use App\Ai\Prompts;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrainTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner'): User
    {
        $tenant = Tenant::create(['name' => 'Teste']);

        return User::create(['tenant_id' => $tenant->id, 'role' => $role, 'name' => 'Ana', 'email' => "ana-{$role}@teste.com", 'password' => 'segredo123']);
    }

    public function test_starts_empty_with_all_questions(): void
    {
        $res = $this->actingAs($this->user(), 'api')->getJson('/api/brain')->assertOk();

        $this->assertCount(4, $res->json('sections'));
        $this->assertSame(['answered' => 0, 'total' => 12, 'percent' => 0], $res->json('progress'));
    }

    public function test_saves_answers_partially_and_ignores_unknown_keys(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'api')->putJson('/api/brain', ['answers' => ['vida_antes' => 'Eu era bancário.', 'hack' => 'x']])->assertOk();
        $res = $this->actingAs($user, 'api')->putJson('/api/brain', ['answers' => ['pilares' => "Oração\nLeitura"]])->assertOk();

        $this->assertSame('Eu era bancário.', $res->json('answers.vida_antes'));
        $this->assertSame("Oração\nLeitura", $res->json('answers.pilares'));
        $this->assertNull($res->json('answers.hack'));
        $this->assertSame(2, $res->json('progress.answered'));
    }

    public function test_member_cannot_edit(): void
    {
        $this->actingAs($this->user('member'), 'api')
            ->putJson('/api/brain', ['answers' => ['vida_antes' => 'x']])->assertForbidden();
    }

    public function test_answers_go_into_the_system_prompt(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'api')->putJson('/api/brain', ['answers' => [
            'dores_superadas' => 'Fiquei dois anos sem conseguir orar depois que meu pai morreu.',
            'apelido' => 'o pastor dos adolescentes',
        ]]);
        $system = Prompts::system($user->tenant->fresh());

        $this->assertStringContainsString('# Segundo Cérebro do criador', $system);
        $this->assertStringContainsString('Fiquei dois anos sem conseguir orar', $system);
        $this->assertStringContainsString('o pastor dos adolescentes', $system);
        $this->assertStringContainsString('nunca acrescente detalhes', $system);
    }

    public function test_empty_brain_tells_ai_not_to_invent_stories(): void
    {
        $tenant = $this->user()->tenant;

        $this->assertSame('', Brain::forPrompt($tenant));
        $this->assertStringContainsString('não invente histórias pessoais', Prompts::system($tenant));
    }

}
