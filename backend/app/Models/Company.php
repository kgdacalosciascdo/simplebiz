<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'currency',
        'locale',
        'created_by',
        'legal_name',
        'primary_contact_name',
        'primary_contact_email',
        'primary_contact_phone',
        'principal_address',
        'fiscal_year_start_month',
        'setup_status',
        'setup_completed_at',
        'default_currency_id',
        'default_branch_id',
        'default_payment_term_id',
        'default_payment_method_id',
        'default_warehouse_id',
        'default_stock_location_id',
        'default_expense_category_id',
        'default_expense_account_title_id',
        'opening_balance_offset_account_title_id',
        'opening_balance_lock_date',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance_lock_date' => 'date',
            'allow_negative_cash_balance' => 'boolean',
            'negative_balance_requires_approval' => 'boolean',
            'cash_movement_lock_date' => 'date',
            'created_by' => 'integer',
            'principal_address' => 'array',
            'fiscal_year_start_month' => 'integer',
            'setup_completed_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['status', 'is_owner', 'last_active_at'])
            ->withTimestamps();
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }
}
