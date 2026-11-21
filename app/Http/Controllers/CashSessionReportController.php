<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Services\CashSessionService;
use Illuminate\Contracts\View\View;

/**
 * The printed cash count (corte) of a closed session, on the ticket printer.
 */
class CashSessionReportController extends Controller
{
    public function __invoke(CashSession $cashSession, CashSessionService $cashSessions): View
    {
        abort_if($cashSession->isOpen(), 404);

        return view('cash.report', [
            'session' => $cashSession->load(['openedBy', 'closedBy']),
            ...$cashSessions->report($cashSession),
        ]);
    }
}
