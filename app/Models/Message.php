<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const ROLE_HUMAN = 'human';

    protected $fillable = [
        'conversation_id',
        'external_message_id',
        'role',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
