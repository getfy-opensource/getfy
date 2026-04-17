<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberTurma;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberBuilderLinkExistingAlunoTest extends TestCase
{
    public function test_store_new_aluno_links_existing_aluno_by_email_without_password(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $productA = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'pa'.substr(uniqid('', true), -8),
            'name' => 'Produto A',
        ]);

        $productB = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'pb'.substr(uniqid('', true), -8),
            'name' => 'Produto B',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
            'email' => 'existente@test.com',
            'password' => Hash::make('secret123'),
        ]);
        $productA->users()->attach($aluno->id);

        $turma = MemberTurma::create([
            'product_id' => $productB->id,
            'name' => 'Turma 1',
            'position' => 1,
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.alunos.store', ['produto' => $productB]),
            [
                'name' => 'Nome Atualizado',
                'email' => 'Existente@Test.com',
                'turma_id' => $turma->id,
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('linked_existing', true);
        $this->assertTrue($productB->users()->where('users.id', $aluno->id)->exists());
        $this->assertTrue($turma->users()->where('users.id', $aluno->id)->exists());
        $this->assertSame('Nome Atualizado', $aluno->fresh()->name);
    }

    public function test_store_new_aluno_requires_password_when_email_is_new(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'pn'.substr(uniqid('', true), -8),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('member-builder.alunos.store', ['produto' => $product]),
            [
                'name' => 'Novo',
                'email' => 'novo_unico@test.com',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }
}
