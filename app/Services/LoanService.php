<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\ReceivedRepayment;
use App\Models\ScheduledRepayment;
use App\Models\User;
use Carbon\Carbon;

class LoanService
{
    /**
     * Create a Loan
     *
     * @param  User  $user
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  int  $terms
     * @param  string  $processedAt
     *
     * @return Loan
     */
    public function createLoan(User $user, int $amount, string $currencyCode, int $terms, string $processedAt): Loan
    {
        //
        $loan = Loan::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'terms' => $terms,
            'outstanding_amount' => $amount,
            'currency_code' => $currencyCode,
            'processed_at' => $processedAt,
            'status' => Loan::STATUS_DUE,
        ]);

        $baseAmount = floor($amount / $terms);
        $lastAmount = $amount - ($baseAmount * ($terms - 1));

        for ($i = 0; $i < $terms; $i++) {
            ScheduledRepayment::create([
                'loan_id' => $loan->id,
                'amount' => $i == $terms - 1 ? $lastAmount : $baseAmount,
                'outstanding_amount' => $i == $terms - 1 ? $lastAmount : $baseAmount,
                'currency_code' => $currencyCode,
                'due_date' => Carbon::parse($processedAt)->addMonths($i + 1)->format('Y-m-d'),
                'status' => ScheduledRepayment::STATUS_DUE,
            ]);
        }

        return $loan;
    }

    /**
     * Repay Scheduled Repayments for a Loan
     *
     * @param  Loan  $loan
     * @param  int  $amount
     * @param  string  $currencyCode
     * @param  string  $receivedAt
     *
     * @return ReceivedRepayment
     */
    public function repayLoan(Loan $loan, int $amount, string $currencyCode, string $receivedAt): ReceivedRepayment
    {
        //
        $receivedRepayment = ReceivedRepayment::create([
            'loan_id' => $loan->id,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'received_at' => $receivedAt,
        ]);

        $remainingAmount = $amount;

        foreach ($loan->scheduledRepayments()->where('status', ScheduledRepayment::STATUS_DUE)->get() as $repayment) {
            if ($remainingAmount <= 0) {
                break;
            }

            if ($remainingAmount >= $repayment->outstanding_amount) {
                $remainingAmount -= $repayment->outstanding_amount;
                $repayment->update([
                    'outstanding_amount' => 0,
                    'status' => ScheduledRepayment::STATUS_REPAID,
                ]);
            } else {
                $repayment->update([
                    'outstanding_amount' => $repayment->outstanding_amount - $remainingAmount,
                    'status' => ScheduledRepayment::STATUS_PARTIAL,
                ]);
                $remainingAmount = 0;
            }
        }

        $loan_outstanding_amount = $loan->outstanding_amount - $amount;

        $loan->update([
            'outstanding_amount' => $loan_outstanding_amount,
            'status' => $loan_outstanding_amount == 0 ? Loan::STATUS_REPAID : Loan::STATUS_DUE,
        ]);

        return $receivedRepayment;
    }
}