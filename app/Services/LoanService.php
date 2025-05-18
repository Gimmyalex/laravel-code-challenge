<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\ReceivedRepayment;
use App\Models\ScheduledRepayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanService
{
    /**
     * Create a Loan and its Scheduled Repayments.
     *
     * @param  User  $user
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  int  $terms
     * @param  string  $processedAt Date string (Y-m-d)
     *
     * @return Loan
     * @throws \InvalidArgumentException If terms are not 3 or 6.
     */
    public function createLoan(User $user, int $amount, string $currencyCode, int $terms, string $processedAt): Loan
    {
         // Add basic validation based on common sense, though tests might not cover failure
         if (!in_array($terms, [3, 6])) {
             throw new \InvalidArgumentException("Loan terms must be 3 or 6.");
         }
         if ($amount <= 0) {
              throw new \InvalidArgumentException("Loan amount must be positive.");
         }

        // Use a transaction
        return DB::transaction(function () use ($user, $amount, $currencyCode, $terms, $processedAt) {
            // 1. Create the Loan
            $loan = $user->loans()->create([
                'amount' => $amount,
                'terms' => $terms,
                'outstanding_amount' => $amount, // Initially outstanding is the full amount
                'currency_code' => $currencyCode,
                'processed_at' => $processedAt, // Save as date string as received
                'status' => Loan::STATUS_DUE, // Initially due
            ]);

            // 2. Calculate and Create Scheduled Repayments
            $baseRepaymentAmount = floor($amount / $terms); // Integer division
            $remainder = $amount % $terms;

            $scheduledRepaymentsData = [];
            // Ensure date is parsed correctly, especially if processedAt is just Y-m-d
            $loanProcessedDate = Carbon::parse($processedAt)->startOfDay();

            for ($i = 1; $i <= $terms; $i++) {
                $repaymentAmount = $baseRepaymentAmount;
                // Add remainder to the last repayment installment
                if ($i === $terms) {
                    $repaymentAmount += $remainder;
                }

                $scheduledRepaymentsData[] = [
                    'loan_id' => $loan->id, // Link to the loan
                    'amount' => $repaymentAmount,
                    'outstanding_amount' => $repaymentAmount, // Initially outstanding is the full repayment amount
                    'currency_code' => $currencyCode,
                    'due_date' => $loanProcessedDate->copy()->addMonths($i)->format('Y-m-d'), // Add one month, format as Y-m-d
                    'status' => ScheduledRepayment::STATUS_DUE, // Initially due
                    'created_at' => now(), // Manually set timestamps for bulk insert
                    'updated_at' => now(),
                ];
            }

            // Bulk insert scheduled repayments
            ScheduledRepayment::insert($scheduledRepaymentsData);

            // Reload loan to ensure scheduledRepayments relationship is populated if needed
            // $loan->load('scheduledRepayments'); // Not strictly necessary for the tests as written

            return $loan;
        });
    }

    /**
     * Apply a received repayment amount to a Loan's Scheduled Repayments
     * and update the Loan's outstanding amount and status.
     *
     * @param  Loan  $loan The loan to repay.
     * @param  int  $amount Received amount.
     * @param  string  $currencyCode Currency code of the received amount.
     * @param  string  $receivedAt Date string (Y-m-d) when the payment was received.
     *
     * @return ReceivedRepayment The created received repayment record.
     * @throws \Exception If currency codes do not match or loan is already repaid or amount is not positive.
     */
    public function repayLoan(Loan $loan, int $amount, string $currencyCode, string $receivedAt): ReceivedRepayment
    {
        // Add basic validation
        if ($loan->currency_code !== $currencyCode) {
             throw new \Exception("Currency mismatch: Loan currency is {$loan->currency_code}, received is {$currencyCode}");
        }
         if ($loan->status === Loan::STATUS_REPAID) {
              throw new \Exception("Loan {$loan->id} is already repaid.");
         }
         if ($amount <= 0) {
             throw new \InvalidArgumentException("Repayment amount must be positive.");
         }
          // Cannot repay more than the current outstanding amount (basic check)
         if ($amount > $loan->outstanding_amount) {
              $amount = $loan->outstanding_amount;
               if ($amount <= 0) { // If outstanding was 0 or less, nothing to do
                   throw new \Exception("Loan {$loan->id} has no outstanding amount left.");
               }
         }


        // Use a transaction
        return DB::transaction(function () use ($loan, $amount, $currencyCode, $receivedAt) {
            $remainingReceivedAmount = $amount;

            // 1. Create the Received Repayment record
            $receivedRepayment = $loan->receivedRepayments()->create([
                'amount' => $amount, // Store the full received amount
                'currency_code' => $currencyCode,
                'received_at' => $receivedAt, // Save as date string
            ]);

            // 2. Apply the received amount to due/partial scheduled repayments chronologically
            $scheduledRepaymentsToPay = $loan->scheduledRepayments()
                ->whereIn('status', [ScheduledRepayment::STATUS_DUE, ScheduledRepayment::STATUS_PARTIAL])
                ->orderBy('due_date')
                ->get();

            foreach ($scheduledRepaymentsToPay as $scheduledRepayment) {
                if ($remainingReceivedAmount <= 0) {
                    break; // No more received amount to apply
                }

                $outstandingBeforePayment = $scheduledRepayment->outstanding_amount;

                if ($remainingReceivedAmount >= $outstandingBeforePayment) {
                    // Received amount fully covers this scheduled repayment's outstanding amount
                    $remainingReceivedAmount -= $outstandingBeforePayment; // Use the *full* outstanding amount of this installment
                    $scheduledRepayment->outstanding_amount = 0;
                    $scheduledRepayment->status = ScheduledRepayment::STATUS_REPAID;

                } else {
                    // Received amount partially covers this scheduled repayment
                    $scheduledRepayment->outstanding_amount -= $remainingReceivedAmount;
                    $remainingReceivedAmount = 0; // Use the rest of the received amount
                    $scheduledRepayment->status = ScheduledRepayment::STATUS_PARTIAL;
                }

                // Save the changes to the scheduled repayment
                $scheduledRepayment->save();
            }

            // 3. Update the Loan's outstanding amount and status
            $loan->outstanding_amount -= $amount; // Subtract the *full* received amount

            // Ensure outstanding amount doesn't go below zero due to integer math or edge cases
            if ($loan->outstanding_amount < 0) {
                 $loan->outstanding_amount = 0;
            }

            // If total outstanding is zero (or less due to previous integer math), the loan is fully repaid
            if ($loan->outstanding_amount <= 0) {
                $loan->status = Loan::STATUS_REPAID;
            }

            $loan->save();

            // 4. Return the created Received Repayment record
            return $receivedRepayment;
        });
    }
}