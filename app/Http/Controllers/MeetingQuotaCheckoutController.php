<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MeetingQuota\StripeCheckoutUnavailableException;
use App\Http\Requests\MeetingQuota\StoreCheckoutRequest;
use App\Models\MeetingPack;
use App\UseCases\MeetingQuota\CreateCheckoutSessionAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MeetingQuotaCheckoutController extends Controller
{
    public function select(): View
    {
        return view('meeting-quota.checkout-select', [
            'plans' => MeetingPack::query()->published()->ordered()->get(),
        ]);
    }

    public function create(
        StoreCheckoutRequest $request,
        CreateCheckoutSessionAction $action,
    ): RedirectResponse {
        try {
            $checkoutUrl = $action(
                $request->user(),
                $request->validated('meeting_pack_id'),
            );
        } catch (StripeCheckoutUnavailableException) {
            return back()->with(
                'error',
                '決済の準備に失敗しました。時間をおいて再度お試しください。',
            );
        }

        return redirect()->away($checkoutUrl);
    }
}
