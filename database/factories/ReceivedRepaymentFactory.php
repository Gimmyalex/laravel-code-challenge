<?php

namespace Database\Factories;

use App\Models\Loan; 
use App\Models\ReceivedRepayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class ReceivedRepaymentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ReceivedRepayment::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(), // Automatically create a loan if none provided
            'amount' => $this->faker->numberBetween(50, 5000),
            'currency_code' => $this->faker->randomElement([Loan::CURRENCY_SGD, Loan::CURRENCY_VND]), 
            'received_at' => $this->faker->dateTimeThisYear()->format('Y-m-d'), 
        ];
    }

    /**
     * Indicate a specific received_at date.
     *
     * @param string|\DateTimeInterface $date
     * @return Factory
     */
    public function receivedAt($date): Factory
    {
        return $this->state(fn (array $attributes) => [
            'received_at' => $date,
        ]);
    }
}