<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    public const STATUS_AI = 'ai';

    public const STATUS_HUMAN = 'human';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CLOSED = 'closed';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_PORTFOLIO = 'portfolio';

    protected $attributes = [
        'channel' => self::CHANNEL_WHATSAPP,
    ];

    protected $fillable = [
        'customer_id',
        'channel',
        'status',
        'stage',
        'intent',
        'complexity',
        'summary',
        'collected_data',
        'transferred_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'collected_data' => 'array',
            'transferred_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isHandledByAi(): bool
    {
        return $this->status === self::STATUS_AI;
    }

    public function transferToHuman(): void
    {
        $this->update([
            'status' => self::STATUS_HUMAN,
            'transferred_at' => now(),
        ]);
    }
}
