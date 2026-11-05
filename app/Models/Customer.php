<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'whatsapp_id',
        'portfolio_session_id',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function activeConversation(?string $channel = null): ?Conversation
    {
        $query = $this->conversations()
            ->whereNot('status', Conversation::STATUS_CLOSED);

        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        return $query->latest()->first();
    }
}
