<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BarangayOfficialProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'province_id',
        'city_municipality',
        'barangay',
        'position',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
    ];

    public function province()
    {
        return $this->belongsTo(Province::class);
    }
}