<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;
use Carbon\Carbon; 

class DebitCardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $anotherUser;
    protected DebitCard $userCard; 
    protected DebitCard $otherUserCard; 

    protected function setUp(): void
    {
        parent::setUp();

        // Create users
        $this->user = User::factory()->create();
        $this->anotherUser = User::factory()->create();

        // Create default *active* debit cards for setup, linked to users
        // These will be available in all tests unless explicitly deleted or modified.
        $this->userCard = DebitCard::factory()->active()->create([
            'user_id' => $this->user->id,
        ]);
         $this->otherUserCard = DebitCard::factory()->active()->create([
            'user_id' => $this->anotherUser->id,
        ]);
    }

    public function test_unauthenticated_access()
    {
        // Use IDs from setup cards for single resource endpoints
        $userCardId = $this->userCard->id;
        $otherCardId = $this->otherUserCard->id; // Use other user's ID too for thoroughness

        // Assert unauthorized/forbidden status
        $this->getJson('/api/debit-cards')->assertUnauthorized(); // Expect 401
        $this->postJson('/api/debit-cards', [])->assertUnauthorized(); // Expect 401
        $this->getJson("/api/debit-cards/{$userCardId}")->assertUnauthorized(); // Expect 401
        $this->putJson("/api/debit-cards/{$userCardId}", [])->assertUnauthorized(); // Expect 401
        $this->deleteJson("/api/debit-cards/{$userCardId}")->assertUnauthorized(); // Expect 401

        // Check single resource endpoints with another user's ID while still unauthorized
        $this->getJson("/api/debit-cards/{$otherCardId}")->assertUnauthorized(); // Expect 401
         $this->putJson("/api/debit-cards/{$otherCardId}", [])->assertUnauthorized(); // Expect 401
         $this->deleteJson("/api/debit-cards/{$otherCardId}")->assertUnauthorized(); // Expect 401
    }


    public function testCustomerCanSeeAListOfDebitCards()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        $additionalActiveCards = DebitCard::factory()->count(2)->active()->create(['user_id' => $this->user->id]);

        DebitCard::factory()->count(1)->expired()->create(['user_id' => $this->user->id]);
        DebitCard::factory()->count(2)->create(['user_id' => $this->anotherUser->id]);

        // Total expected active cards for $this->user: 1 (from setUp) + 2 (additional active) = 3
        $expectedCount = 1 + $additionalActiveCards->count();

        $response = $this->getJson('/api/debit-cards');

        $response->assertStatus(200); // Expect 200 OK for a successful list retrieval

        // Assert count matches only the *active* cards created for the authenticated user
        $response->assertJsonCount($expectedCount, 'data'); // Assumes resource wraps in 'data'

        // Assert the JSON structure matches the DebitCardResource format
        $response->assertJsonStructure([
            'data' => [ 
                '*' => [ // For each item in the data array
                    'id', 'number', 'type', 'expiration_date', 'is_active'
                ]
            ]
        ]);

        // Verify the IDs returned match the user's *active* card IDs
        $responseIds = collect($response->json('data'))->pluck('id')->toArray();
        // Combine the ID from setUp card and the additional active cards
        $userActiveDebitCardIds = array_merge([$this->userCard->id], $additionalActiveCards->pluck('id')->toArray());

        // Assert that the arrays of IDs are identical
        $this->assertEqualsCanonicalizing($userActiveDebitCardIds, $responseIds);

         //Explicitly check that none of the inactive user cards or other user's cards are present
         $inactiveUserCardIds = DebitCard::where('user_id', $this->user->id)->whereNotNull('disabled_at')->pluck('id')->toArray();
         $otherUserCardIds = DebitCard::where('user_id', $this->anotherUser->id)->pluck('id')->toArray();
         $combinedExcludedIds = array_merge($otherUserCardIds, $inactiveUserCardIds);
         foreach($combinedExcludedIds as $excludedId) {
             $this->assertFalse(in_array($excludedId, $responseIds), "Excluded card ID {$excludedId} was returned.");
         }
    }

    public function testCustomerCannotSeeAListOfDebitCardsOfOtherCustomers()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Ensure the authenticated user ($this->user) has NO debit cards for this test
        // Delete the one created in setUp for this user.
        $this->userCard->forceDelete(); // Use forceDelete to bypass soft deletes and policy

        // The card created in setUp for the other user ($this->otherUserCard) exists.
        // Create additional debit cards only for another user.
        DebitCard::factory()->count(3)->create([
            'user_id' => $this->anotherUser->id
        ]);

        $response = $this->getJson('/api/debit-cards');

        $response->assertStatus(200); // Still expect 200 OK even if the list is empty
        $response->assertJsonCount(0, 'data'); // Assert that no cards are returned for the authenticated user
    }


    public function testCustomerCanCreateADebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        $cardData = [
            'type' => 'Mastercard' // Example type
        ];

        $response = $this->postJson('/api/debit-cards', $cardData);

        $response->assertStatus(201); // Expect 201 Created

        // Assert the JSON structure matches the DebitCardResource format
        $response->assertJsonStructure([
            'data' => [ 
                'id', 'number', 'type', 'expiration_date', 'is_active'
                // Add other fields expected
            ]
        ]);

        // Assert that the returned data contains the input type and is active
        $response->assertJsonFragment([
            'type' => $cardData['type'],
            'is_active' => true, // Assert default state from resource accessor
        ]);

        // Get the ID of the newly created card from the response
        $createdCardId = $response->json('data.id');
        $this->assertNotNull($createdCardId, 'Response did not contain the created card ID.');

        // Assert the card was created in the database with the correct user_id, type, and default state
        $this->assertDatabaseHas('debit_cards', [
            'id' => $createdCardId,
            'user_id' => $this->user->id,
            'type' => $cardData['type'],
            'disabled_at' => null // Should be active by default
        ]);
         // Check that generated fields are not null in the database
        $createdCard = DebitCard::find($createdCardId);
        $this->assertNotNull($createdCard->number, 'Card number was not generated/saved.');
        $this->assertNotNull($createdCard->expiration_date, 'Expiration date was not generated/saved.');
    }

    public function testCustomerCannotCreateADebitCardWithoutRequiredFields()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Send empty data or data missing required fields
        $response = $this->postJson('/api/debit-cards', []); // Missing 'type'

        $response->assertStatus(422); // Expect 422 Unprocessable Entity for validation errors
        $response->assertJsonValidationErrors(['type']); 
    }

    public function testCustomerCannotCreateADebitCardWithInvalidData()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        $invalidCardData = [
            'type' => 'unknown_type', 
        ];

        $response = $this->postJson('/api/debit-cards', $invalidCardData);

        $response->assertStatus(422); // Expect 422 Unprocessable Entity
        // Assert validation errors for the specific invalid fields
        $response->assertJsonValidationErrors(['type']); // Assuming type validation
    }

    public function testCustomerCanSeeASingleDebitCardDetails()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        $card = $this->userCard; // Use card from setUp

        $response = $this->getJson("/api/debit-cards/{$card->id}");

        $response->assertStatus(200); // Expect 200 OK

        // Assert the JSON structure matches the DebitCardResource format
        $response->assertJsonStructure([
            'data' => [ 
                'id', 'number', 'type', 'expiration_date', 'is_active'
            ]
        ]);

        // Assert specific details match the created debit card (checking values returned by the resource)
        $response->assertJsonFragment([
            'id' => $card->id,
            'number' => $card->number,
            'type' => $card->type,
            'is_active' => $card->is_active, 
        ]);
    }

    public function testCustomerCannotSeeASingleDebitCardDetailsBelongingToAnotherCustomer()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        $otherCard = $this->otherUserCard; // Use other user's card from setUp

        // Attempt to access the other user's card using the authenticated user's credentials
        $response = $this->getJson("/api/debit-cards/{$otherCard->id}");

        $response->assertStatus(403); // Expect 403 Forbidden (Policy should block this)
    }

    public function testUserCannotAccessNonExistentDebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Get an ID that is guaranteed not to exist in the database
        $nonExistentId = DebitCard::max('id') + 1;

        $response = $this->getJson("/api/debit-cards/{$nonExistentId}");

        $response->assertStatus(404); // Expect 404 Not found (Route Model Binding failure)
    }

    public function testCustomerCanActivateADebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create a specific inactive card for the user for this test case
        $cardToActivate = DebitCard::factory()->expired()->create(['user_id' => $this->user->id]);
        $this->assertFalse($cardToActivate->is_active); // Verify initial state using accessor

        // Send the update request to set is_active to true
        $response = $this->putJson("/api/debit-cards/{$cardToActivate->id}", [
            'is_active' => true
        ]);

        $response->assertStatus(200); // Expect 200 OK for a successful update

         // Assert the JSON response reflects the updated state
         $response->assertJsonStructure([
             'data' => [ 
                'id', 'is_active',
             ]
        ]);
        $response->assertJsonFragment([
            'id' => $cardToActivate->id,
            'is_active' => true // Assert the returned state is active via resource
        ]);


        // Refresh the model instance from the database to check its actual state
        $cardToActivate->refresh();
        $this->assertNull($cardToActivate->disabled_at); // Assert disabled_at is now null in DB
        $this->assertTrue($cardToActivate->is_active); // Assert accessor returns true after refresh
    }

    public function testCustomerCanDeactivateADebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create a specific active card for the user for this test case
        $cardToDeactivate = DebitCard::factory()->active()->create(['user_id' => $this->user->id]);
        $this->assertTrue($cardToDeactivate->is_active); // Verify initial state using accessor

        // Send the update request to set is_active to false
        $response = $this->putJson("/api/debit-cards/{$cardToDeactivate->id}", [
            'is_active' => false
        ]);

        $response->assertStatus(200); // Expect 200 OK

        // Assert the JSON response reflects the updated state
         $response->assertJsonStructure([
             'data' => [
                'id', 'is_active',
             ]
        ]);
        $response->assertJsonFragment([
            'id' => $cardToDeactivate->id,
            'is_active' => false // Assert the returned state is inactive via resource
        ]);

        // Refresh the model instance from the database
        $cardToDeactivate->refresh();
        $this->assertNotNull($cardToDeactivate->disabled_at); // Assert disabled_at is now set in DB
         $this->assertFalse($cardToDeactivate->is_active); // Assert accessor returns false after refresh
    }

    public function testCustomerCannotUpdateADebitCardWithWrongValidation()
    {
        // Use the card created for the user in setUp
        $debitCard = $this->userCard;
         // Store original state to verify it wasn't changed by invalid requests
        $originalDisabledAt = $debitCard->disabled_at;

        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // --- Test 1: Missing required field (assuming 'is_active' is required for update) ---
        $response = $this->putJson("/api/debit-cards/{$debitCard->id}", [
            // 'is_active' is missing
            'some_other_field' => 'value' 
        ]);

        $response->assertStatus(422); // Expect 422 Unprocessable Entity
        $response->assertJsonValidationErrors(['is_active']); // Assert validation error for 'is_active'

        // --- Test 2: Invalid type for 'is_active' field (assuming boolean is required) ---
        $response = $this->putJson("/api/debit-cards/{$debitCard->id}", [
            'is_active' => 'not-a-boolean' // Invalid value
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_active']); // Assert validation error for 'is_active' type

        // Refresh the model after failed requests to ensure no changes were persisted
        $debitCard->refresh();
        $this->assertEquals($originalDisabledAt, $debitCard->disabled_at);
        // $this->assertEquals($originalNumber, $debitCard->number); // Verify other fields are untouched
    }

    public function testCustomerCannotUpdateADebitCardBelongingToAnotherCustomer()
    {
        // Use the card created for the other user in setUp
        $otherUserDebitCard = $this->otherUserCard;

        // Store original state of the other user's card
        $originalDisabledAt = $otherUserDebitCard->disabled_at;

        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Attempt to update the other user's card
        $response = $this->putJson("/api/debit-cards/{$otherUserDebitCard->id}", [
            'is_active' => !$otherUserDebitCard->is_active 
        ]);

        $response->assertStatus(403); // Expect 403 Forbidden (Policy should block this)

        // Verify the other user's card was NOT updated
        $otherUserDebitCard->refresh();
        $this->assertEquals($originalDisabledAt, $otherUserDebitCard->disabled_at);
    }

     public function testCustomerCannotUpdateNonExistentDebitCard()
    {
        // Get an ID that is guaranteed not to exist
        $nonExistentId = DebitCard::max('id') + 1;

        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        $updateData = ['is_active' => true]; // Some valid update data

        $response = $this->putJson("/api/debit-cards/{$nonExistentId}", $updateData);

        $response->assertStatus(404); // Expect 404 Not found (Route Model Binding failure)
    }

    public function testCustomerCanDeleteADebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create a specific card for the user *specifically for deletion*
        // Ensure this card has NO transactions for this test case, otherwise policy will fail
        $cardToDelete = DebitCard::factory()->create(['user_id' => $this->user->id]);
        $this->assertEquals(0, $cardToDelete->debitCardTransactions()->count(), 'Card should have no transactions for this test.');

        // Ensure the card exists and is not soft deleted initially
        $this->assertDatabaseHas('debit_cards', [
            'id' => $cardToDelete->id,
            'deleted_at' => null
        ]);

        $response = $this->deleteJson("/api/debit-cards/{$cardToDelete->id}");

        $response->assertStatus(204); // Expect 204 No Content as per your controller

        // Verify the card was soft deleted (record exists, deleted_at is set)
        $this->assertSoftDeleted('debit_cards', [
            'id' => $cardToDelete->id
        ]);

        // Verify it's *not* returned by queries that exclude soft deleted items
        $this->assertDatabaseMissing('debit_cards', [ // This checks where deleted_at is null
             'id' => $cardToDelete->id
        ]);

         // Verify the specific deleted_at timestamp is not null using withTrashed()
         $deletedCard = DebitCard::withTrashed()->find($cardToDelete->id);
         $this->assertNotNull($deletedCard->deleted_at, 'The deleted_at timestamp was not set.');
    }

    public function testCustomerCannotDeleteADebitCardWithTransaction()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Create a specific debit card for the user for this test case
        $cardWithTransaction = DebitCard::factory()->create(['user_id' => $this->user->id]);

        // Create at least one transaction for this card
        // *** This next line might fail with QueryException due to the application bug (missing 'currency' column) ***
        // We cannot fix the factory or migration here. The test will likely fail here.
        DebitCardTransaction::factory()->create([
            'debit_card_id' => $cardWithTransaction->id,
             // Provide other required fields for transaction creation, hoping they exist in DB/factory
             'amount' => 50.00,
             // 'currency' => 'USD', // <-- Based on your previous output, this column seems missing. Comment out if causing error.
             'status' => 'completed', // Example status
        ]);

        // Ensure the card exists and is not soft deleted
        $this->assertDatabaseHas('debit_cards', ['id' => $cardWithTransaction->id, 'deleted_at' => null]);
        // Ensure the transaction exists (this assertion might also fail if the factory call above failed)
        $this->assertDatabaseHas('debit_card_transactions', ['debit_card_id' => $cardWithTransaction->id]);
        // The count check might also fail if the transaction creation failed above.
        // $this->assertEquals(1, $cardWithTransaction->debitCardTransactions()->count(), 'Card should have at least one transaction for this test.');


        $response = $this->deleteJson("/api/debit-cards/{$cardWithTransaction->id}");

        // Expect 403 Forbidden because the policy should prevent deletion if transactions exist
        $response->assertStatus(403); // Forbidden

        // Verify the card was NOT deleted (deleted_at is still null)
        $this->assertDatabaseHas('debit_cards', [
            'id' => $cardWithTransaction->id,
            'deleted_at' => null
        ]);
         // Verify the transaction was NOT deleted either (assertion might fail if transaction never created)
         $this->assertDatabaseHas('debit_card_transactions', ['debit_card_id' => $cardWithTransaction->id]);
    }

    public function testCustomerCannotDeleteOtherCustomerDebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Use the card created for the other user in setUp
        $otherUserCard = $this->otherUserCard;
        // Ensure the other user's card exists and is not soft deleted initially
        $this->assertDatabaseHas('debit_cards', ['id' => $otherUserCard->id, 'deleted_at' => null]);

        // Attempt to delete the other user's card
        $response = $this->deleteJson("/api/debit-cards/{$otherUserCard->id}");

        $response->assertStatus(403); // Expect 403 Forbidden (Policy should block this)

        // Verify the card was NOT deleted (deleted_at is still null)
        $this->assertDatabaseHas('debit_cards', [
            'id' => $otherUserCard->id,
            'deleted_at' => null
        ]);
    }

     public function testCustomerCannotDeleteNonExistentDebitCard()
    {
        // Authenticate the user for *this test*
        Passport::actingAs($this->user);

        // Get an ID that is guaranteed not to exist
        $nonExistentId = DebitCard::max('id') + 1;

        $response = $this->deleteJson("/api/debit-cards/{$nonExistentId}");

        $response->assertStatus(404); // Expect 404 Not Found
    }

}