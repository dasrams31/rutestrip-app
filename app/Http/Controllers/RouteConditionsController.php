<?php
namespace App\Http\Controllers;

use App\Models\HikingRoute;
use App\Services\RouteConditionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RouteConditionsController extends Controller
{
    /**
     * Lazily loaded by the route page: basecamp/summit points, trailhead
     * location and current weather (all cached in RouteConditionsService).
     */
    public function show(HikingRoute $route, RouteConditionsService $conditions): JsonResponse
    {
        return response()
            ->json($conditions->conditions($route), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * "Masuk / Daftar untuk memberi ulasan": remember the route page so login
     * or registration (redirect()->intended()) brings the user back to the reviews.
     */
    public function loginToReview(Request $request, HikingRoute $route): RedirectResponse
    {
        $back = route('routes.show', $route) . '#ulasan';

        if (Auth::check()) {
            return redirect($back);
        }

        $request->session()->put('url.intended', $back);

        return redirect()->route($request->query('to') === 'register' ? 'register' : 'login');
    }
}
