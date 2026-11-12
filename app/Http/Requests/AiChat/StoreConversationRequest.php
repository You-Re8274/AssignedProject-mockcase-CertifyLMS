<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiChatConversation::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source' => ['nullable', Rule::in(['widget', 'full-screen'])],
            'section_id' => ['nullable', 'ulid', Rule::exists('sections', 'id')],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
