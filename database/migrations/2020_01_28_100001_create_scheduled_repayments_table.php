<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScheduledRepaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('scheduled_repayments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_id'); // Use unsignedBigInteger for FK if loans.id is bigIncrements

            // Added columns based on test assertions
            $table->integer('amount');
            $table->integer('outstanding_amount'); // Amount remaining to be paid for this specific repayment
            $table->string('currency_code');
            $table->date('due_date');
            $table->string('status')->default(\App\Models\ScheduledRepayment::STATUS_DUE); // 'due', 'partial', 'repaid'

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('loan_id')
                ->references('id')
                ->on('loans')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('scheduled_repayments');
        Schema::enableForeignKeyConstraints();
    }
}