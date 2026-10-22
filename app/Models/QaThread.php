<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use Database\Factories\QaThreadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 資格に紐づく質問掲示板スレッド。解決状態と投稿者・回答を持つ。
 */
class QaThread extends Model
{
    /** @use HasFactory<QaThreadFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'certification_id',
        'user_id',
        'title',
        'body',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'status' => QaThreadStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Certification, $this>
     */
    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<QaReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(QaReply::class)->orderBy('created_at');
    }

    /**
     * Restrict public board results to published certifications assigned to the viewer.
     *
     * @param Builder<QaThread> $query
     *
     * @return Builder<QaThread>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query
            ->whereHas('certification', function (Builder $certifications) use ($viewer): void {
                $certifications->published();

                if ($viewer->role === UserRole::Coach) {
                    $certifications->whereHas(
                        'coaches',
                        fn (Builder $coaches) => $coaches->where('users.id', $viewer->id),
                    );
                }
            });
    }
}
