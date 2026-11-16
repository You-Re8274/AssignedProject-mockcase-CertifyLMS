<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class ShowCheckoutSuccessRequest extends FormRequest
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
            'session_id' => ['required', 'string', 'max:255'],
        ];
    }
}
