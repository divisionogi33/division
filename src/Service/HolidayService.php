<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class HolidayService
{
    public function __construct(private CacheInterface $cache)
    {
    }

    /**
     * Връща официалните празници за България за дадена година: ['YYYY-MM-DD' => 'Име на празника']
     */
    public function getHolidaysForYear(int $year): array
    {
        return $this->cache->get('bg_holidays_' . $year, function (ItemInterface $item) use ($year) {
            $item->expiresAfter(86400 * 30); // Кеш за 30 дни

            $url = sprintf('https://date.nager.at/api/v3/PublicHolidays/%d/BG', $year);
            $context = stream_context_create([
                'http' => ['timeout' => 3],
            ]);

            $json = @file_get_contents($url, false, $context);
            if ($json === false) {
                // Ако няма интернет или API-то не отговаря, кешираме кратко и връщаме празен масив
                $item->expiresAfter(300);
                return [];
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                return [];
            }

            $holidays = [];
            foreach ($data as $h) {
                if (isset($h['date'], $h['localName'])) {
                    $holidays[$h['date']] = $h['localName'];
                }
            }

            return $holidays;
        });
    }
    public function countWorkingDays(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $workingDays = 0;
        $cur = $start;
        $holidaysCache = [];

        while ($cur->format('Y-m-d') <= $end->format('Y-m-d')) {
            $y = (int) $cur->format('Y');
            if (!isset($holidaysCache[$y])) {
                $holidaysCache[$y] = $this->getHolidaysForYear($y);
            }

            $dow = (int) $cur->format('N');
            if ($dow <= 5 && !isset($holidaysCache[$y][$cur->format('Y-m-d')])) {
                $workingDays++;
            }

            $cur = $cur->modify('+1 day');
        }

        return $workingDays;
    }

    /**
     * Смята работните дни и месечната норма (по 8 ч.) за конкретен месец
     */
    public function getMonthlyNorm(int $year, int $month): array
    {
        $holidays = $this->getHolidaysForYear($year);
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $daysInMonth = (int) $monthStart->format('t');

        $workingDays = 0;
        $monthHolidays = [];

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $monthStart->setDate($year, $month, $d);
            $dateStr = $date->format('Y-m-d');
            $dow = (int) $date->format('N'); // 1..5 = Пон-Пет, 6..7 = Съб-Нед

            if (isset($holidays[$dateStr])) {
                $monthHolidays[$d] = $holidays[$dateStr];
            }

            if ($dow <= 5 && !isset($holidays[$dateStr])) {
                $workingDays++;
            }
        }

        return [
            'workingDays' => $workingDays,
            'normHours' => $workingDays * 8,
            'holidays' => $monthHolidays,
        ];
    }
}
