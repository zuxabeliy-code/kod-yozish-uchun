<?php
/**
 * Gemini REST mijozi. API kaliti faqat shu serverda ishlatiladi, ilovaga hech qachon yuborilmaydi.
 */
declare(strict_types=1);

final class GeminiException extends RuntimeException
{
    public int $httpCode;

    public function __construct(string $message, int $httpCode = 0)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

final class GeminiClient
{
    private string $apiKey;
    private string $base;

    public function __construct(string $apiKey, string $base = 'https://generativelanguage.googleapis.com/v1beta')
    {
        if ($apiKey === '') {
            throw new GeminiException('Gemini API kaliti sozlanmagan', 500);
        }
        $this->apiKey = $apiKey;
        $this->base = rtrim($base, '/');
    }

    /** Matnli suhbat. $history = [['role'=>'user'|'model','text'=>'...'], ...] */
    public function chat(string $model, string $system, array $history, string $message): string
    {
        $contents = [];
        foreach ($history as $turn) {
            $contents[] = ['role' => $turn['role'], 'parts' => [['text' => $turn['text']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $body = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $contents,
            'generationConfig'  => ['temperature' => 0.7, 'maxOutputTokens' => 2048],
        ];
        return self::extractText($this->generate($model, $body, 60));
    }

    /** Ovozni matnga aylantirish (o'zbek nutqi). */
    public function transcribe(string $model, string $audioBytes, string $mime): string
    {
        $body = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [
                    ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($audioBytes)]],
                    ['text' => "Bu audio yozuvdagi nutqni so'zma-so'z matnga aylantir. "
                        . "Til asosan o'zbekcha; o'zbekchani lotin alifbosida yoz. "
                        . "Faqat aytilgan matnni qaytar, izoh qo'shma. Nutq bo'lmasa, bo'sh javob qaytar."],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 1024],
        ];
        return trim(self::extractText($this->generate($model, $body, 60), true));
    }

    /** Matnni ovozga aylantirish. Natija: WAV baytlar. */
    public function speak(string $model, string $voice, string $text): string
    {
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
            ],
        ];
        $resp = $this->generate($model, $body, 90);
        foreach ($resp['candidates'][0]['content']['parts'] ?? [] as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (!$inline || empty($inline['data'])) {
                continue;
            }
            $bytes = base64_decode((string)$inline['data'], true);
            if ($bytes === false || $bytes === '') {
                break;
            }
            if (strncmp($bytes, 'RIFF', 4) === 0) {
                return $bytes; // allaqachon WAV
            }
            $mime = (string)($inline['mimeType'] ?? $inline['mime_type'] ?? '');
            $rate = preg_match('/rate=(\d+)/', $mime, $m) ? (int)$m[1] : 24000;
            return self::pcmToWav($bytes, $rate);
        }
        throw new GeminiException('TTS javobida audio topilmadi', 502);
    }

    /** Kalitni tekshirish (installer uchun). */
    public function ping(): bool
    {
        [$code] = $this->request('GET', $this->base . '/models?pageSize=1', null, 15);
        return $code === 200;
    }

    private function generate(string $model, array $body, int $timeout): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,80}$/', $model)) {
            throw new GeminiException('Model nomi noto\'g\'ri: ' . $model, 500);
        }
        $url = $this->base . '/models/' . $model . ':generateContent';
        [$code, $raw] = $this->request('POST', $url, json_encode($body, JSON_UNESCAPED_UNICODE), $timeout);
        $data = json_decode($raw, true);
        if ($code !== 200) {
            $msg = is_array($data) ? (string)($data['error']['message'] ?? 'Noma\'lum xato') : 'Javob o\'qilmadi';
            throw new GeminiException('Gemini xatosi (' . $code . '): ' . mb_substr($msg, 0, 180), $code);
        }
        if (!is_array($data)) {
            throw new GeminiException('Gemini javobi JSON emas', 502);
        }
        return $data;
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $url, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        $headers = ['x-goog-api-key: ' . $this->apiKey, 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new GeminiException('Gemini bilan aloqa yo\'q: ' . $err, 504);
        }
        return [$code, (string)$raw];
    }

    public static function extractText(array $resp, bool $allowEmpty = false): string
    {
        $cand = $resp['candidates'][0] ?? null;
        if ($cand === null) {
            $reason = $resp['promptFeedback']['blockReason'] ?? 'javob yo\'q';
            throw new GeminiException('Gemini javob bermadi: ' . $reason, 502);
        }
        $out = '';
        foreach ($cand['content']['parts'] ?? [] as $part) {
            if (!empty($part['thought'])) {
                continue; // fikrlash qismlarini ko'rsatmaymiz
            }
            $out .= (string)($part['text'] ?? '');
        }
        $out = trim($out);
        if ($out === '' && !$allowEmpty) {
            throw new GeminiException('Gemini bo\'sh javob qaytardi (' . ($cand['finishReason'] ?? '?') . ')', 502);
        }
        return $out;
    }

    public static function pcmToWav(string $pcm, int $rate = 24000, int $channels = 1, int $bits = 16): string
    {
        $byteRate = $rate * $channels * intdiv($bits, 8);
        $blockAlign = $channels * intdiv($bits, 8);
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $channels, $rate, $byteRate, $blockAlign, $bits)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }
}
