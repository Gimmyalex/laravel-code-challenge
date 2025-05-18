<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LoanFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Loan::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        $amount = $this->faker->numberBetween(1000, 10000);
        $terms = $this->faker->randomElement([3, 6]);
        $processedAt = $this->faker->date();

        return [
            'user_id' => User::factory(), // Automatically creates a user if none provided
            'amount' => $amount,
            'terms' => $terms,
            'outstanding_amount' => $amount, // Initially outstanding amount is full amount
            'currency_code' => $this->faker->randomElement([Loan::CURRENCY_SGD, Loan::CURRENCY_VND]), // Use Loan constants
            'processed_at' => $processedAt,
            'status' => Loan::STATUS_DUE, // Initially due
        ];
    }

    /**
     * Indicate that the loan is repaid.
     *
     * @return Factory
     */
    public function repaid(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'outstanding_amount' => 0,
            'status' => Loan::STATUS_REPAID,
        ]);
    }

     /**
     * Indicate a specific processed_at date.
     *
     * @param string|\DateTimeInterface $date
     * @return Factory
     */
    public function processedAt($date): Factory
    {
        return $this->state(fn (array $attributes) => [
            'processed_at' => $date,
        ]);
    }
}