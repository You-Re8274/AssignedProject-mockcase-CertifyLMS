<?php

declare(strict_types=1);

namespace App\Http\Requests\QaBoard;

use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');
        $reply = $this->route('reply');

        return $thread instanceof QaThread
            && $reply instanceof QaReply
            && $reply->qa_thread_id === $thread->id
            && $this->user()?->can('update', $reply) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => '回答本文'];
    }
}
