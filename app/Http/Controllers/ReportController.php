<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * Display the blank forms page.
     */
    public function blankForms()
    {
        return Inertia::render('Reports/BlankForms/Index');
    }
}
