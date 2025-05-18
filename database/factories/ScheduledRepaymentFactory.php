<?php

namespace Database\Factories;

use App\Models\Loan; 
use App\Models\ScheduledRepayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class ScheduledRepaymentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ScheduledRepayment::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        $amount = $this->faker->numberBetween(100, 2000);
        $dueDate = $this->faker->dateTimeBetween('now', '+1 year')->format('Y-m-d');

        return [
            'loan_id' => Loan::factory(), // Automatically create a loan if none provided
            'amount' => $amount,
            'outstanding_amount' => $amount, // Initially outstanding amount is full amount
            'currency_code' => $this->faker->randomElement([Loan::CURRENCY_SGD, Loan::CURRENCY_VND]), 
            'due_date' => $dueDate,
            'status' => ScheduledRepayment::STATUS_DUE, // Initially due
        ];
    }

     /**
     * Indicate that the scheduled repayment is repaid.
     *
     * @return Factory
     */
    public function repaid(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'outstanding_amount' => 0,
            'status' => ScheduledRepayment::STATUS_REPAID,
        ]);
    }

     /**
     * Indicate that the scheduled repayment is partial.
     *
     * @param int $paidAmount The amount that has been paid (outstanding_amount will be amount - paidAmount)
     * @return Factory
     */
    public function partial(int $paidAmount): Factory
    {
         return $this->state(function (array $attributes) use ($paidAmount) {
             $outstanding = $attributes['amount'] - $paidAmount;
             // Ensure outstanding is not negative and less than original amount
             if ($outstanding < 0 || $outstanding >= $attributes['amount']) {
                  throw new \InvalidArgumentException("Partial paidAmount ($paidAmount) must be less than scheduled amount ({$attributes['amount']}) and greater than 0.");
             }
            return [
                'outstanding_amount' => $outstanding,
                'status' => ScheduledRepayment::STATUS_PARTIAL,
            ];
        });
    }

    /**
     * Indicate a specific due_date.
     *
     * @param string|\DateTimeInterface $date
     * @return Factory
     */
    public function dueDate($date): Factory
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => $date,
        ]);
    }
}