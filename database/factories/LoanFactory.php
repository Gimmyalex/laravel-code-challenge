<?php

namespace Database\Factories;

use App\Models\Loan;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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

        return [
            'amount' => $amount,
            'terms' => $this->faker->randomElement([3, 6]),
            'outstanding_amount' => $amount,
            'currency_code' => Loan::CURRENCY_VND,
            'processed_at' => Carbon::now()->subDays($this->faker->numberBetween(1, 30)),
            'status' => Loan::STATUS_DUE,
            'user_id' => fn () => User::factory()->create(),
        ];
    }
}