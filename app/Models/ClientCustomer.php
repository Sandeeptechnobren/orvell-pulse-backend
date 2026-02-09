<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientCustomer extends Model
{
    protected $table = 'client_customer';

    protected $fillable = [
        'client_id',
        'space_id',
        'customer_id',
        'first_interaction_at',
    ];
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
