<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'subject',
        'email_content',
        'sms_content',
        'push_content',
        'variables',
        'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function parseContent($content, $data)
    {
        // Replace variable placeholders with actual data
        if (!empty($content) && !empty($data)) {
            foreach ($data as $key => $value) {
                $content = str_replace('{' . $key . '}', $value, $content);
            }
        }

        return $content;
    }

    public function parseEmailContent($data)
    {
        return $this->parseContent($this->email_content, $data);
    }

    public function parseSmsContent($data)
    {
        return $this->parseContent($this->sms_content, $data);
    }

    public function parsePushContent($data)
    {
        return $this->parseContent($this->push_content, $data);
    }

    public function parseSubject($data)
    {
        return $this->parseContent($this->subject, $data);
    }
}
