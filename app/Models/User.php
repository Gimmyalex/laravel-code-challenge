<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany; 
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use App\Models\Loan; 

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * An User has many debit cards
     *
     * @return HasMany
     */
    public function debitCards(): HasMany // Add return type hint for clarity
    {
        return $this->hasMany(DebitCard::class, 'user_id');
    }

    // Add this relationship 
    /**
     * An User has many Loans.
     *
     * @return HasMany
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'user_id');
    }
    
}