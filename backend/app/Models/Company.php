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
        'setup_registration_ip',
        'setup_registration_device_id',
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
        'branding',
        'tax_registration_references',
        'date_format',
        'number_format',
        'paper_size',
        'document_preferences',
        'module_preferences',
        'notification_defaults',
        'settings_version',
        'settings_updated_at',
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
            'branding' => 'array',
            'tax_registration_references' => 'array',
            'document_preferences' => 'array',
            'module_preferences' => 'array',
            'notification_defaults' => 'array',
            'settings_updated_at' => 'datetime',
            'settings_version' => 'integer',
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
