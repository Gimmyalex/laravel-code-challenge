<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Carbon\Carbon; 

class DebitCardTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $anotherUser;
    protected DebitCard $userCard; 
    protected DebitCard $anotherUserCard;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users
        $this->user = User::factory()->create();
        $this->anotherUser = User::factory()->create();

        // Create default active debit cards for setup, linked to users
        $this->userCard = DebitCard::factory()->active()->create([
            'user_id' => $this->user->id,
        ]);
        $this->anotherUserCard = DebitCard::factory()->active()->create([
            'user_id' => $this->anotherUser->id,
        ]);

        // IMPORTANT: Do NOT authenticate the user globally in setUp.
        // Authenticate explicitly at the start of *each* test method that requires authentication.
    }

    public function test_unauthenticated_access()
    {
        // No Passport::actingAs() call simulates unauthenticated access

        // Create a temporary transaction to get an ID for single resource tests
        // This transaction creation might fail if the underlying application bugs are present (QueryException)
        $transaction = null;
        try {
            $transaction = DebitCardTransaction::factory()->create(['debit_card_id' => $this->userCard->id]);
            $transactionId = $transaction->id;
        } catch (\Exception $e) {
            // If factory creation fails due to app bugs (like missing currency column), use a dummy ID
            $transactionId = 999; // Use a non-existent ID if creation failed
            echo "\nWarning: Transaction factory failed in test_unauthenticated_access setUp. This test might not fully cover single-resource 401/403.\n";
        }


        // Assert unauthorized/forbidden status for all endpoints
        $this->getJson('/api/debit-card-transactions')->assertUnauthorized(); // Expect 401
        $this->postJson('/api/debit-card-transactions', [])->assertUnauthorized(); // Expect 401
        $this->getJson("/api/debit-card-transactions/{$transactionId}")->assertUnauthorized(); // Expect 401
        if ($transaction) {
             try { $transaction->forceDelete(); } catch (\Exception $e) {} // Handle potential errors during cleanup
        }
    }

    public function testCustomerCanSeeAListOfDebitCardTransactions()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create transactions for the user's card
        // *** This factory call might fail with QueryException due to application bug ***
        $userTransactions = DebitCardTransaction::factory()->count(3)->create([
             'debit_card_id' => $this->userCard->id,
             'amount' => 100, 'currency_code' => 'USD', 'status' => 'completed' 
        ]);

        // Create transactions for another user's card (should NOT be visible)
        // *** This factory call might fail with QueryException due to application bug ***
        DebitCardTransaction::factory()->count(2)->create([
            'debit_card_id' => $this->anotherUserCard->id,
             'amount' => 50, 'currency_code' => 'EUR', 'status' => 'completed' // Example fields
        ]);

        // Request the list of transactions for the user's specific card
        // Controller expects debit_card_id as input (query parameter is common for GET index)
        $response = $this->getJson("/api/debit-card-transactions?debit_card_id={$this->userCard->id}");

        $response->assertStatus(200); // Expect 200 OK

        // Assert count matches only the transactions for the specified user card
        $response->assertJsonCount($userTransactions->count(), 'data'); 

        // Assert the JSON structure matches the DebitCardTransactionResource format
        // Resource returns only 'amount' and 'currency_code'
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'amount',
                    'currency_code'
                ]
            ]
        ]);

        // Verify the data of the transactions returned matches the user's card transactions
        $responseTransactions = $response->json('data');
        $this->assertCount($userTransactions->count(), $responseTransactions); 

        // Check that the returned amounts/currencies match the created transactions
        $returnedAmounts = collect($responseTransactions)->pluck('amount')->sort()->values();
        $createdAmounts = $userTransactions->pluck('amount')->sort()->values();
        $this->assertEquals($createdAmounts, $returnedAmounts);

        $returnedCurrencies = collect($responseTransactions)->pluck('currency_code')->sort()->values();
        $createdCurrencies = $userTransactions->pluck('currency_code')->sort()->values();
        $this->assertEquals($createdCurrencies, $returnedCurrencies);

         // Verify none of the other user's transaction data appears
         $otherUserTransactions = DebitCardTransaction::where('debit_card_id', $this->anotherUserCard->id)->get();
         $otherUserAmounts = $otherUserTransactions->pluck('amount')->toArray();
         $otherUserCurrencies = $otherUserTransactions->pluck('currency_code')->toArray();

         foreach ($responseTransactions as $transactionData) {
             $this->assertFalse(
                 in_array($transactionData['amount'], $otherUserAmounts) &&
                 in_array($transactionData['currency_code'], $otherUserCurrencies),
                 "Other user's transaction data found in response."
             );
         }
    }

    public function testCustomerCannotSeeAListOfDebitCardTransactionsOfOtherCustomerDebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create transactions for another user's card
        // *** This factory call might fail with QueryException due to application bug ***
         DebitCardTransaction::factory()->count(3)->create([
            'debit_card_id' => $this->anotherUserCard->id,
             'amount' => 75, 'currency_code' => 'EUR', 'status' => 'completed' // Example fields
        ]);

        // Attempt to request transactions for the other user's card using the authenticated user
        $response = $this->getJson("/api/debit-card-transactions?debit_card_id={$this->anotherUserCard->id}");

        $response->assertStatus(403); // Expect 403 Forbidden (Policy/Authorization should block)
    }
    public function testCustomerCannotSeeAListOfTransactionsWithoutDebitCardId()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Attempt to request transactions without the debit_card_id query parameter
        $response = $this->getJson('/api/debit-card-transactions'); // Missing ?debit_card_id=...
        $response->assertStatus(422); // Expect 422 Unprocessable Entity
        $response->assertJsonValidationErrors(['debit_card_id']); // Assert validation error for debit_card_id
    }

    public function testCustomerCanCreateADebitCardTransaction()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Use the user's card created in setUp
        $userCard = $this->userCard;

        $transactionData = [
            'debit_card_id' => $userCard->id, // Must be user's card
            'amount' => 150.75,
            'currency_code' => 'SGD',
        ];

        // *** This request might fail with QueryException due to application bug ***
        $response = $this->postJson('/api/debit-card-transactions', $transactionData);

        $response->assertStatus(201); // Expect 201 Created

        // Assert the JSON structure matches the DebitCardTransactionResource format
        // Resource returns only 'amount' and 'currency_code'
        $response->assertJsonStructure([
            'data' => [
                'amount',
                'currency_code'
                // 'id', 'debit_card_id' are NOT in the resource based on provided code
            ]
        ]);

        // Assert the returned data matches the input
        $response->assertJsonFragment([
            'amount' => $transactionData['amount'],
            'currency_code' => $transactionData['currency_code'],
        ]);

        // Assert the transaction was created in the database and linked to the correct card
        $this->assertDatabaseHas('debit_card_transactions', [
            'debit_card_id' => $userCard->id,
            'amount' => 15075, 
            'currency_code' => $transactionData['currency_code'],
        ]);
         $createdTransaction = DebitCardTransaction::where('debit_card_id', $userCard->id)
            ->where('currency_code', $transactionData['currency_code'])
             ->latest()->first(); // Find the most recently created transaction for this card/currency
         $this->assertNotNull($createdTransaction, 'Transaction was not found in database after creation.');
         $this->assertEquals(15075, $createdTransaction->amount); 

    }

    public function testCustomerCannotCreateADebitCardTransactionToOtherCustomerDebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Use the other user's card created in setUp
        $otherUserCard = $this->anotherUserCard;

        $transactionData = [
            'debit_card_id' => $otherUserCard->id, // Attempt to use another user's card
            'amount' => 200.00,
            'currency_code' => 'IDR',
        ];

        // *** This request might fail with QueryException due to application bug ***
        $response = $this->postJson('/api/debit-card-transactions', $transactionData);

        // Expect 403 Forbidden (Policy should block creating transaction on non-owned card)
        $response->assertStatus(403);

        // Assert the transaction was not created in the database for this card
        $this->assertDatabaseMissing('debit_card_transactions', [
            'debit_card_id' => $otherUserCard->id,
            'amount' => 20000, 
        ]);
    }

    public function testCustomerCannotCreateADebitCardTransactionWithInvalidData()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Use the user's card created in setUp
        $userCard = $this->userCard;

        //  Test 1: Missing required fields 
        $response = $this->postJson('/api/debit-card-transactions', []);

        $response->assertStatus(422); // Expect 422 Unprocessable Entity
        $response->assertJsonValidationErrors(['debit_card_id', 'amount', 'currency_code']); // Assert validation errors for missing fields

        // Test 2: Invalid 'debit_card_id' (non-existent) 
        $invalidTransactionData = [
            'debit_card_id' => DebitCard::max('id') + 1, // Non-existent card ID
            'amount' => 10.00,
            'currency_code' => 'USD',
        ];
        $response = $this->postJson('/api/debit-card-transactions', $invalidTransactionData);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['debit_card_id']); 

        // Test 3: Invalid 'amount' 
         $invalidTransactionData = [
            'debit_card_id' => $userCard->id,
            'amount' => -50.00, // Assuming amount must be positive
            'currency_code' => 'USD',
        ];
        $response = $this->postJson('/api/debit-card-transactions', $invalidTransactionData);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount']);

        //  Test 4: Invalid 'currency_code' (not in allowed CURRENCIES) 
         $invalidTransactionData = [
            'debit_card_id' => $userCard->id,
            'amount' => 100.00,
            'currency_code' => 'XYZ', // Not in DebitCardTransaction::CURRENCIES
        ];
        $response = $this->postJson('/api/debit-card-transactions', $invalidTransactionData);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['currency_code']);

         // Verify no transaction was created with invalid data
         $this->assertDatabaseMissing('debit_card_transactions', [
            'debit_card_id' => $userCard->id,
         ]);
    }

    public function testCustomerCannotCreateTransactionWithInactiveDebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create an inactive card for the user
        $inactiveCard = DebitCard::factory()->expired()->create([
            'user_id' => $this->user->id
        ]);
        $this->assertFalse($inactiveCard->is_active); // Verify it's inactive

        $transactionData = [
            'debit_card_id' => $inactiveCard->id, // Attempt to use inactive card
            'amount' => 300.00,
            'currency_code' => 'THB',
        ];

        // *** This request might fail with QueryException due to application bug ***
        $response = $this->postJson('/api/debit-card-transactions', $transactionData);
        $response->assertStatus(403); // Expect 403 Forbidden

        // Assert the transaction was not created
        $this->assertDatabaseMissing('debit_card_transactions', [
            'debit_card_id' => $inactiveCard->id,
            'amount' => 30000, 
        ]);
    }

    public function testCustomerCanSeeADebitCardTransaction()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create a transaction for the user's card
        // *** This factory call might fail with QueryException due to application bug ***
        $transaction = DebitCardTransaction::factory()->create([
            'debit_card_id' => $this->userCard->id,
             'amount' => 250.00, 'currency_code' => 'VND', 'status' => 'completed' // Example fields
        ]);

        $response = $this->getJson("/api/debit-card-transactions/{$transaction->id}");

        $response->assertStatus(200); // Expect 200 OK

        // Assert the JSON structure matches the DebitCardTransactionResource format
        // Resource returns only 'amount' and 'currency_code'
        $response->assertJsonStructure([
            'data' => [
                'amount',
                'currency_code'
            ]
        ]);

        // Assert the returned data matches the transaction details
        $response->assertJsonFragment([
            'amount' => $transaction->amount,
            'currency_code' => $transaction->currency_code,
        ]);

         // For amount, be careful with float comparisons or assumed integer storage
         // If amount is stored as integer cents (e.g., 25000), resource might return float (250.00) or int (25000).
         // Check resource implementation. If resource returns float, assert float. If int, assert int.
         // Based on your resource/model/migration, resource likely returns the raw DB value (int cents).
         $this->assertEquals(25000, $response->json('data.amount')); // Asserting integer cents from DB
    }

    public function testCustomerCannotSeeADebitCardTransactionAttachedToOtherCustomerDebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create a transaction for another user's card
        // *** This factory call might fail with QueryException due to application bug ***
        $otherUserTransaction = DebitCardTransaction::factory()->create([
            'debit_card_id' => $this->anotherUserCard->id,
             // Add other required fields
             'amount' => 120.00, 'currency_code' => 'IDR', 'status' => 'completed' // Example fields
        ]);

        // Attempt to view the other user's transaction using the authenticated user
        $response = $this->getJson("/api/debit-card-transactions/{$otherUserTransaction->id}");

        $response->assertStatus(403); // Expect 403 Forbidden (Policy should block this)
    }

    public function testUserCannotAccessNonExistentTransaction()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Get an ID that is guaranteed not to exist
        $nonExistentId = DebitCardTransaction::max('id') + 1;

        $response = $this->getJson("/api/debit-card-transactions/{$nonExistentId}");

        $response->assertStatus(404); // Expect 404 Not Found (Route Model Binding failure)
    }

}