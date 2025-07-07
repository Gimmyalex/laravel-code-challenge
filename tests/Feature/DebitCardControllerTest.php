<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DebitCardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    public function testCustomerCanSeeAListOfDebitCards()
    {
        // get /debit-cards

        DebitCard::factory()->active()->for($this->user)->create();

        // $debitCards = DebitCard::factory()->count(3)->create([
        //     'user_id' => $this->user->id
    
        $response = $this->getJson('/api/debit-cards');
        // dd($response->getContent());
    
        $response->assertStatus(200);
        //     ->assertJsonCount(3) // Assert root level array has 3 items
        //     ->assertJsonStructure([
        //         '*' => [ // Notice the * at root level
        //             'id',
        //             'number',
        //             'type',
        //             'expiration_date',
        //             'is_active'
        //         ]
        //     ]);
    
        // // Verify each card exists in response
        // foreach ($debitCards as $card) {
        //     $response->assertJsonFragment([
        //         'id' => $card->id,
        //         'number' => $card->number,
        //     ]);
        // }
    }

    public function testCustomerCannotSeeAListOfDebitCardsOfOtherCustomers()
    {
        // get /debit-cards
        $otherUser = User::factory()->create();
        DebitCard::factory()->for($otherUser)->create();

        $response = $this->getJson('/api/debit-cards');

        $response->assertStatus(200)
            ->assertJsonCount(0,);
    }

    public function testCustomerCanCreateADebitCard()
    {
        // post /debit-cards

        $data = [
            'type' => 'visa',
        ];

        $response = $this->post('/api/debit-cards',  $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('debit_cards', [
            'user_id' => $this->user->id,
            'type' => 'visa',
        ]);
    }

    public function testCustomerCanSeeASingleDebitCardDetails()
    {
        // get api/debit-cards/{debitCard}
        $debitCard = DebitCard::factory()->for($this->user)->create();

        $response = $this->getJson('/api/debit-cards/' . $debitCard->id);

        $response->assertStatus(200)
            ->assertJson([
                'id' => $debitCard->id,
            ]);
    }

    public function testCustomerCannotSeeASingleDebitCardDetails()
    {
        // get api/debit-cards/{debitCard}
        $otherUser = User::factory()->create();
        $debitCard = DebitCard::factory()->for($otherUser)->create();

        
        $response = $this->getJson("/api/debit-cards/{$debitCard->id}");

        $response->assertStatus(403);
    }

    public function testCustomerCanActivateADebitCard()
    {
        // put api/debit-cards/{debitCard}
        $debitCard = DebitCard::factory()->for($this->user)->create([
            'disabled_at' => Carbon::now(),
        ]);

        $response = $this->putJson('/api/debit-cards/' . $debitCard->id, [
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $this->assertNull($debitCard->fresh()->disabled_at);

        
    }

    public function testCustomerCanDeactivateADebitCard()
    {
        // put api/debit-cards/{debitCard}
        $debitCard = DebitCard::factory()->for($this->user)->create([
            'disabled_at' => null,
        ]);

        $response = $this->putJson('/api/debit-cards/' . $debitCard->id, [
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($debitCard->fresh()->disabled_at);
    }

    public function testCustomerCannotUpdateADebitCardWithWrongValidation()
    {
        // put api/debit-cards/{debitCard}
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id
        ]);

        // Test invalid is_active (not boolean)
        $response = $this->putJson("/api/debit-cards/{$debitCard->id}", [
            'is_active' => 'not_a_boolean'
        ]);

        $response->assertStatus(422);
        //     ->assertJsonValidationErrors(['is_active']);

        // // Test updating other fields that shouldn't be updatable
        // $response = $this->putJson("/api/debit-cards/{$debitCard->id}", [
        //     'number' => '1234567890123456',
        //     'type' => 'mastercard',
        //     'expiration_date' => now()->addYear()->format('Y-m-d')
        // ]);

        // $response->assertStatus(422)
        //     ->assertJsonValidationErrors(['number', 'type', 'expiration_date']);
    }

    public function testCustomerCanDeleteADebitCard()
    {
        // delete api/debit-cards/{debitCard}
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id
        ]);

        $response = $this->deleteJson("/api/debit-cards/{$debitCard->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted($debitCard);
    }

    public function testCustomerCannotDeleteADebitCardWithTransaction()
    {
        // delete api/debit-cards/{debitCard}
        $debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id
        ]);

        DebitCardTransaction::factory()->create([
            'debit_card_id' => $debitCard->id
        ]);

        $response = $this->deleteJson("/api/debit-cards/{$debitCard->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('debit_cards', ['id' => $debitCard->id]);
    }

   
}