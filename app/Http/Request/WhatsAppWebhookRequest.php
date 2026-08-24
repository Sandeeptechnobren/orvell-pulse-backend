<?php

namespace App\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

class WhatsAppWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Gateways differ in what they send and we must never reject a delivery,
     * so the payload is read defensively rather than validated.
     */
    public function rules(): array
    {
        return [];
    }

    public function instanceName(): ?string
    {
        $name = $this->input('instance')
            ?? $this->input('instanceName')
            ?? $this->input('instance_name')
            ?? $this->input('instance.name');

        return is_string($name) ? $name : null;
    }

    public function instanceId(): ?string
    {
        $id = $this->input('instance_id') ?? $this->input('instanceId') ?? $this->input('instance.id');

        return $id === null ? null : (string) $id;
    }

    public function sender(): ?string
    {
        $from = $this->input('data.from')
            ?? $this->input('from')
            ?? $this->input('sender')
            ?? $this->input('number')
            ?? $this->input('phone')
            ?? $this->input('chatId')
            ?? $this->input('message.from');

        return $from === null ? null : (string) $from;
    }

    public function recipient(): ?string
    {
        $to = $this->input('data.to') ?? $this->input('to');

        return $to === null ? null : (string) $to;
    }

    public function body(): ?string
    {
        $body = $this->input('data.body')
            ?? $this->input('data.message')
            ?? $this->input('data.text')
            ?? $this->input('message')
            ?? $this->input('text')
            ?? $this->input('body')
            ?? $this->input('message.text')
            ?? $this->input('message.body');

        if (is_array($body)) {
            $body = $body['text'] ?? $body['body'] ?? null;
        }

        return is_string($body) ? $body : null;
    }

    public function type(): string
    {
        return (string) ($this->input('data.type') ?: 'chat');
    }

    public function mediaUrl(): ?string
    {
        return $this->input('data.media_url') ?? $this->input('data.mediaUrl');
    }

    public function timestamp(): ?int
    {
        $ts = $this->input('data.timestamp');

        return is_numeric($ts) ? (int) $ts : null;
    }

    public function messageId(): ?string
    {
        $id = $this->input('data.id.id') ?? $this->input('data.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Our own outgoing messages come back to us; replying to them would loop. */
    public function isEcho(): bool
    {
        return $this->input('data.fromMe') === true || $this->input('fromMe') === true;
    }

    /** Delivery receipts and status updates are not messages. */
    public function isProtocolEvent(): bool
    {
        return in_array($this->input('event'), ['message.ack', 'ack', 'status'], true);
    }

    public function isText(): bool
    {
        return in_array($this->type(), ['chat', 'text'], true);
    }

    public function hasMessage(): bool
    {
        return $this->sender() !== null && $this->body() !== null && $this->body() !== '';
    }

    /**
     * Identifies one message so repeated deliveries collapse into one.
     */
    public function fingerprint(): string
    {
        $id = $this->messageId();

        $raw = $id
            ? $this->instanceName().'|'.$id
            : $this->instanceName().'|'.$this->sender().'|'.$this->timestamp().'|'.$this->body();

        return 'wa:'.md5($raw);
    }
}
