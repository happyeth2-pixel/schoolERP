<?php

namespace App\Models;

use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;

class ReportCardSnapshot extends Model
{
    use NeverHardDeleted;

    public $timestamps = false;

    protected $fillable = [
        'student_id', 'section_id', 'overall_average', 'rank', 'ranked_count', 'class_size',
        'rules_version', 'missing_mark_policy', 'basis', 'subjects', 'generated_by',
    ];

    protected function casts(): array
    {
        return ['basis' => 'array', 'subjects' => 'array', 'created_at' => 'datetime'];
    }
}
