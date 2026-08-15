<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'whatsapp_id',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function activeConversation(): ?Conversation
    {
        return $this->conversations()
            ->whereNot('status', Conversation::STATUS_CLOSED)
            ->latest()
            ->first();
    }
}
