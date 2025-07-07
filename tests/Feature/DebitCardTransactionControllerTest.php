<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DebitCardTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected DebitCard $debitCard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id
        ]);
        Passport::actingAs($this->user);
    }

    public function testCustomerCanSeeAListOfDebitCardTransactions()
    {
        // get /debit-card-transactions
        DebitCardTransaction::factory()->for($this->debitCard)->create();
        $response = $this->getJson('/api/debit-card-transactions?debit_card_id='.$this->debitCard->id);
        $response->assertStatus(200)->assertJsonCount(1);
    }

    public function testCustomerCannotSeeAListOfDebitCardTransactionsOfOtherCustomerDebitCard()
    {
        // get /debit-card-transactions
        $otherUser = User::factory()->create();
        $otherDebitCard = DebitCard::factory()->for($otherUser)->create();
        DebitCardTransaction::factory()->for($otherDebitCard)->create();
        $response = $this->getJson('/api/debit-card-transactions?debit_card_id=' . $otherDebitCard->id);
        $response->assertStatus(403);
    }

    public function testCustomerCanCreateADebitCardTransaction()
    {
        // post /debit-card-transactions
        $response = $this->postJson('/api/debit-card-transactions', [
            'debit_card_id' => $this->debitCard->id,
            'amount' => 100,
            'currency_code' => 'IDR',
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('debit_card_transactions', [
            'debit_card_id' => $this->debitCard->id,
            'amount' => 100,
            'currency_code' => 'IDR',
        ]);
    }

    public function testCustomerCannotCreateADebitCardTransactionToOtherCustomerDebitCard()
    {
        // post /debit-card-transactions
        $otherUser = User::factory()->create();
        $otherDebitCard = DebitCard::factory()->for($otherUser)->create();
        $response = $this->postJson('/api/debit-card-transactions', [
            'debit_card_id' => $otherDebitCard->id,
            'amount' => 100,
            'currency_code' => 'USD',
        ]);
        $response->assertStatus(403);
    }

    public function testCustomerCanSeeADebitCardTransaction()
    {
        // get /debit-card-transactions/{debitCardTransaction}
        $transaction = DebitCardTransaction::factory()->for($this->debitCard)->create();
        $response = $this->getJson('/api/debit-card-transactions/' . $transaction->id);
        $response->assertStatus(200)
            ->assertJson([
                'amount' => $transaction->amount,
                'currency_code' => $transaction->currency_code,
            ]);
    }

    public function testCustomerCannotSeeADebitCardTransactionAttachedToOtherCustomerDebitCard()
    {
        // get /debit-card-transactions/{debitCardTransaction}
        $otherUser = User::factory()->create();
        $otherDebitCard = DebitCard::factory()->for($otherUser)->create();
        $transaction = DebitCardTransaction::factory()->for($otherDebitCard)->create();
        $response = $this->getJson('/api/debit-card-transactions/' . $transaction->id);
        $response->assertStatus(403);
    }

    // Extra bonus for extra tests :)
}