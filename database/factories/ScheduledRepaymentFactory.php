<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\ScheduledRepayment;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        $amount = $this->faker->numberBetween(1000, 10000);
        $status = $this->faker->randomElement([ScheduledRepayment::STATUS_DUE, ScheduledRepayment::STATUS_REPAID]);

        return [
            'amount' => $amount,
            'outstanding_amount' => $status === ScheduledRepayment::STATUS_REPAID ? 0 : $amount,
            'currency_code' => Loan::CURRENCY_VND,
            'due_date' => $this->faker->date(),
            'status' => $status,
            'loan_id' => fn () => Loan::factory()->create(),
        ];
    }
}