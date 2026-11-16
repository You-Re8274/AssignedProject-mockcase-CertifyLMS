<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stripe による追加面談パック購入の監査記録。パックの内容は購入時点のスナップショットを保持する。
 */
class Payment extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'meeting_pack_id',
        'meeting_pack_name',
        'meeting_count',
        'amount',
        'currency',
        'status',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
    ];

    protected $casts = [
        'meeting_count' => 'integer',
        'amount' => 'integer',
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MeetingPack, $this>
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }

    /**
     * @return HasMany<MeetingQuotaTransaction, $this>
     */
    public function quotaTransactions(): HasMany
    {
        return $this->hasMany(MeetingQuotaTransaction::class, 'related_payment_id');
    }
}
