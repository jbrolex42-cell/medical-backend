<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Emergency;
use App\Models\User; 

class ReportController extends Controller 
{
    public function index()
    {
        return view('report.index', [
            'title' => 'Report Emergency'
        ]);
    }

    // ... keep the rest of your store() and track() methods the same
}