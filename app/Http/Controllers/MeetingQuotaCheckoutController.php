<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MeetingQuota\StripeCheckoutUnavailableException;
use App\Http\Requests\MeetingQuota\ShowCheckoutSuccessRequest;
use App\Http\Requests\MeetingQuota\StoreCheckoutRequest;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\UseCases\MeetingQuota\CreateCheckoutSessionAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MeetingQuotaCheckoutController extends Controller
{
    public function select(Request $request): View|RedirectResponse
    {
        if ($request->query('checkout') === 'canceled') {
            return redirect()
                ->route('meeting-quota.checkout.select')
                ->with('info', '決済はキャンセルされました。面談回数は変更されていません。');
        }

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

    public function success(ShowCheckoutSuccessRequest $request): View
    {
        $payment = Payment::query()
            ->with('meetingPack')
            ->where('user_id', $request->user()->id)
            ->where('stripe_checkout_session_id', $request->validated('session_id'))
            ->firstOrFail();

        return view('meeting-quota.success', compact('payment'));
    }
}
