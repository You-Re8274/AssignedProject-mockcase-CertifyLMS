<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Student;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'meeting_pack_id' => [
                'required',
                'string',
                Rule::exists('meeting_packs', 'id')
                    ->where('status', MeetingPackStatus::Published->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'meeting_pack_id' => '面談パック',
        ];
    }
}
