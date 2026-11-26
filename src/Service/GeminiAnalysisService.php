<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiAnalysisService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(string:GEMINI_API_KEY)%')]
        private string $apiKey,
    ) {}

    public function analyzeWorkload(array $staffStats, string $monthLabel, int $normHours): string
    {
        $statsJson = json_encode($staffStats, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Ти си военен щабен плановик. Анализирай служебната натовареност на личния състав за месец {$monthLabel} (месечна норма: {$normHours} ч.).

Данни (часове, разлика от нормата, изпълнени и активни задачи):
{$statsJson}

Направи кратък, структуриран анализ на български език:
1. Кои служители са пренатоварени и кои са недотоварени спрямо нормата.
2. Как е разпределението на задачите (кой има най-много активни/изпълнени задачи).
3. Конкретни препоръки за балансиране на натоварването (без излишни общи приказки).
PROMPT;

        return $this->callGemini($prompt, false);
    }

    public function generateNextMonthScheduleJson(
        array $usersData,
        string $targetMonth,
        int $daysInMonth,
        int $normHours
    ): array {
        $usersJson = json_encode($usersData, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Ти си военен щабен плановик. Генерирай реален дневен график за месец {$targetMonth} ({$daysInMonth} дни, месечна норма {$normHours} ч.).

Служители и текущо натоварване:
{$usersJson}

Правила:
1. Използвай само кодове: "Д" (24ч.), "РС" (8ч.), "ДР" (12ч.), "КОМ" (0ч.).
2. След всяко "Д" служителят почива минимум 2 календарни дни (няма "Д" или "РС" в следващите 2 дни).
3. Всеки ден от 1 до {$daysInMonth} да има поне 1 човек на "Д".
4. Профили с "division" в името или административни (0 ч.) да нямат "Д" и "РС".
5. Пренатоварените служители да имат по-малко "Д", а недотоварените — повече, за да се изравнят максимално близо до {$normHours} ч.
6. Ако за служител има зададени lockedLeaves (одобрени отпуски/болнични "О" / "Б"), запази ги на съответните дни и не слагай други смени на тези дни.

Върни САМО валиден JSON в следния формат (без markdown и без обяснения извън JSON):
{
  "summary": "Кратко щабно резюме (2-3 изречения)",
  "schedule": [
    {
      "userId": 1,
      "days": { "1": "РС", "2": "Д", "5": "РС" }
    }
  ]
}
PROMPT;

        $raw = $this->callGemini($prompt, true);
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
        $decoded = json_decode($clean, true);

        if (!is_array($decoded) || !isset($decoded['schedule']) || !is_array($decoded['schedule'])) {
            throw new \RuntimeException('Gemini не върна валиден JSON график.');
        }

        return $decoded;
    }

    private function callGemini(string $prompt, bool $asJson = false): string
    {
        $apiKey = trim($this->apiKey);
        if ($apiKey === '') {
            throw new \RuntimeException('Липсва GEMINI_API_KEY в .env.local.');
        }

        $models = [
            'gemini-3.5-flash-lite',
            'gemini-3.5-flash-lite', // 2-ри опит при временно 503
            'gemini-3.5-flash',
            'gemini-3.8-flash',
        ];

        $errors = [];

        foreach ($models as $model) {
            $cleanModel = preg_replace('#^models/#', '', trim($model));
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
                urlencode($cleanModel)
            );

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ];

            if ($asJson) {
                $payload['generationConfig'] = [
                    'responseMimeType' => 'application/json',
                ];
            }

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'x-goog-api-key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => 60,
                    'max_duration' => 90,
                    'extra' => [
                        'curl' => [
                            \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
                        ],
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                $data = $response->toArray(false);

                if ($statusCode >= 200 && $statusCode < 300) {
                    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if ($text) {
                        return $text;
                    }
                    $errors[] = sprintf('(%s) Празен отговор', $cleanModel);
                    continue;
                }

                $errorMsg = $data['error']['message'] ?? ('HTTP ' . $statusCode);
                $errors[] = sprintf('(%s) %s', $cleanModel, $errorMsg);

                if ($statusCode === 503 || $statusCode === 429) {
                    sleep(2);
                }
            } catch (\Throwable $e) {
                $errors[] = sprintf('(%s) %s', $cleanModel, $e->getMessage());
            }
        }

        throw new \RuntimeException('Gemini API грешка: ' . implode(' | ', $errors));
    }
}
