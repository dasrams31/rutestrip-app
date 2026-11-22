<?php

namespace App\Http\Controllers;

use App\Models\HikingRoute;
use Illuminate\Http\Request;

/**
 * /akun/kiriman: the signed-in hiker's own trails (GPX uploads from the site and
 * the app, and app recordings) with the admin review status. Same data as
 * GET /api/v1/me/submissions, so the site and the app show the same thing.
 */
class SubmissionController extends Controller
{
    public function index(Request $request)
    {
        $submissions = HikingRoute::withoutGlobalScope('published')
            ->where('uploaded_by', $request->user()->id)
            ->latest('id')
            ->paginate(20, ['id', 'name', 'distance_km', 'elevation_gain_m', 'source', 'review_status', 'review_note', 'reviewed_at', 'created_at']);

        return view('account.submissions', compact('submissions'));
    }
}
