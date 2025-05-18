<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany; 
use Illuminate\Database\Eloquent\SoftDeletes; 

class Loan extends Model
{
    public const STATUS_DUE = 'due';
    public const STATUS_REPAID = 'repaid';

    public const CURRENCY_SGD = 'SGD';
    public const CURRENCY_VND = 'VND';

    use HasFactory, SoftDeletes; 

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'loans';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'amount',
        'terms',
        'outstanding_amount',
        'currency_code',
        'processed_at',
        'status',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'processed_at',
        'deleted_at', // Add soft delete date
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
     protected $casts = [
         'processed_at' => 'date:Y-m-d', // Cast processed_at to date
     ];


    /**
     * A Loan belongs to a User
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * A Loan has many Scheduled Repayments
     *
     * @return HasMany
     */
    public function scheduledRepayments(): HasMany
    {
        return $this->hasMany(ScheduledRepayment::class, 'loan_id');
    }

     // Add the relationship to Received Repayments
     /**
     * A Loan has many Received Repayments.
     *
     * @return HasMany
     */
    public function receivedRepayments(): HasMany
    {
        return $this->hasMany(ReceivedRepayment::class, 'loan_id');
    }
}