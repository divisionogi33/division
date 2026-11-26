<?php

namespace App\Controller;

use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LegendItem;
use App\Entity\Shift;
use App\Entity\Task;
use App\Entity\User;
use App\Service\GeminiAnalysisService;
use App\Service\HolidayService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em, HolidayService $holidayService): Response
    {
        $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC']);
        // Само обикновени потребители (без админи)
        $regularUsers = array_values(array_filter(
            $allUsers,
            fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles(), true)
        ));
        $pendingLeaves = $em->getRepository(LeaveRequest::class)->findBy(
            ['status' => 'pending'],
            ['createdAt' => 'ASC']
        );
        $pendingLeaveInfo = [];
        foreach ($pendingLeaves as $lv) {
            $pendingLeaveInfo[$lv->getId()] = [
                'workingDays' => $holidayService->countWorkingDays($lv->getStartDate(), $lv->getEndDate()),
                'balances' => $em->getRepository(LeaveBalance::class)->findBy(
                    ['user' => $lv->getUser()],
                    ['year' => 'ASC']
                ),
            ];
        }

        $legends = $em->getRepository(LegendItem::class)->findAll();
        $shifts = $em->getRepository(Shift::class)->findBy([], ['startDate' => 'DESC']);

        // Филтър за задачите: today | week | all (по подразбиране: all)
        $filter = $request->query->get('filter', 'all');
        $qb = $em->getRepository(Task::class)->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC');

        if ($filter === 'today') {
            $start = new \DateTimeImmutable('today 00:00:00');
            $end = new \DateTimeImmutable('today 23:59:59');
            $qb->where('t.dueDate BETWEEN :s AND :e')->setParameter('s', $start)->setParameter('e', $end);
        } elseif ($filter === 'week') {
            $start = new \DateTimeImmutable('monday this week 00:00:00');
            $end = new \DateTimeImmutable('sunday this week 23:59:59');
            $qb->where('t.dueDate BETWEEN :s AND :e')->setParameter('s', $start)->setParameter('e', $end);
        }
        $tasks = $qb->getQuery()->getResult();

        // Часове само за редовните потребители
        $hoursStats = [];
        foreach ($regularUsers as $u) {
            $hoursStats[$u->getId()] = [
                'name' => trim(($u->getMilitaryRank() ?? '') . ' ' . $u->getFullName()),
                'position' => $u->getPosition() ?? '',
                'hours' => 0.0,
                'shiftsCount' => 0,
            ];
        }

        foreach ($shifts as $shift) {
            $uid = $shift->getUser()->getId();
            if (isset($hoursStats[$uid])) {
                $hoursStats[$uid]['hours'] += $shift->getCalculatedHours();
                $hoursStats[$uid]['shiftsCount']++;
            }
        }

        return $this->render('admin/index.html.twig', [
            'regularUsers' => $regularUsers,
            'legends' => $legends,
            'tasks' => $tasks,
            'shifts' => $shifts,
            'hoursStats' => array_values($hoursStats),
            'currentFilter' => $filter,
            'pendingLeaves' => $pendingLeaves,
            'pendingLeaveInfo' => $pendingLeaveInfo,
        ]);
    }

    #[Route('/ai/analyze', name: 'app_admin_ai_analyze', methods: ['POST'])]
    public function aiAnalyze(
        EntityManagerInterface $em,
        HolidayService $holidayService,
        GeminiAnalysisService $gemini
    ): JsonResponse {
        try {
            [$staffStats, $monthLabel, $normHours] = $this->collectMonthlyWorkloadStats($em, $holidayService);
            $result = $gemini->analyzeWorkload($staffStats, $monthLabel, $normHours);

            return $this->json(['ok' => true, 'text' => $result]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ai/suggest-schedule', name: 'app_admin_ai_suggest_schedule', methods: ['POST'])]
    public function aiSuggestSchedule(
        EntityManagerInterface $em,
        HolidayService $holidayService,
        GeminiAnalysisService $gemini
    ): JsonResponse {
        try {
            [$staffStats] = $this->collectMonthlyWorkloadStats($em, $holidayService);

            $nextMonthStart = new \DateTimeImmutable('first day of next month 00:00:00');
            $nextMonthEnd = $nextMonthStart->modify('last day of this month 23:59:59');
            $targetYear = (int) $nextMonthStart->format('Y');
            $targetMonthNum = (int) $nextMonthStart->format('m');
            $targetMonth = $nextMonthStart->format('Y-m');
            $daysInMonth = (int) $nextMonthStart->format('t');

            $normData = $holidayService->getMonthlyNorm($targetYear, $targetMonthNum);
            $normHours = $normData['normHours'];

            // Дни от следващия месец (уикенди + официални празници)
            $daysMeta = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = $nextMonthStart->setDate($targetYear, $targetMonthNum, $d);
                $dow = (int) $date->format('N');
                $daysMeta[$d] = [
                    'num' => $d,
                    'isWeekend' => $dow >= 6,
                    'holidayName' => $normData['holidays'][$d] ?? null,
                ];
            }

            // Вече записани отпуски/болнични за следващия месец, за да ги пази Gemini
            $existingNextShifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
                ->where('s.startDate <= :end AND s.endDate >= :start')
                ->setParameter('start', $nextMonthStart)
                ->setParameter('end', $nextMonthEnd)
                ->getQuery()
                ->getResult();

            $lockedDaysByUser = [];
            foreach ($existingNextShifts as $s) {
                $code = mb_strtoupper(trim($s->getLegendItem()->getCode()));
                if (!in_array($code, ['О', 'Б'], true)) {
                    continue;
                }
                $uid = $s->getUser()->getId();
                $cur = max($s->getStartDate(), $nextMonthStart);
                $end = min($s->getEndDate(), $nextMonthEnd);
                while ($cur->format('Y-m-d') <= $end->format('Y-m-d')) {
                    $lockedDaysByUser[$uid][(string) (int) $cur->format('j')] = $code;
                    $cur = $cur->modify('+1 day');
                }
            }

            // Подготовка на данни за Gemini + карта за визуализацията
            $usersMap = [];
            foreach ($staffStats as &$st) {
                $uid = $st['userId'];
                $st['lockedLeaves'] = $lockedDaysByUser[$uid] ?? [];
                $usersMap[$uid] = [
                    'name' => $st['name'],
                    'position' => $st['position'],
                ];
            }
            unset($st);

            // Легенди с реалните цветове и часове от базата
            $legendsMeta = [];
            foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {
                $code = mb_strtoupper(trim($l->getCode()));
                $legendsMeta[$code] = [
                    'code' => $code,
                    'name' => $l->getName(),
                    'color' => $l->getColorHex(),
                    'hours' => $l->getDefaultDailyHours() * $l->getMultiplier(),
                ];
            }

            $aiData = $gemini->generateNextMonthScheduleJson($staffStats, $targetMonth, $daysInMonth, $normHours);

            return $this->json([
                'ok' => true,
                'targetMonth' => $targetMonth,
                'targetMonthLabel' => $nextMonthStart->format('m.Y'),
                'daysInMonth' => $daysInMonth,
                'workingDays' => $normData['workingDays'],
                'normHours' => $normHours,
                'daysMeta' => $daysMeta,
                'legendsMeta' => $legendsMeta,
                'usersMap' => $usersMap,
                'summary' => $aiData['summary'] ?? '',
                'schedule' => $aiData['schedule'] ?? [],
            ]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    #[Route('/ai/apply-schedule', name: 'app_admin_ai_apply_schedule', methods: ['POST'])]
    public function aiApplySchedule(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $targetMonth = (string) ($payload['targetMonth'] ?? '');
        $schedule = $payload['schedule'] ?? [];

        if (!preg_match('/^(\d{4})-(\d{2})$/', $targetMonth, $m) || !is_array($schedule)) {
            return $this->json(['ok' => false, 'error' => 'Невалидни данни за запис.'], 400);
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);
        $daysInMonth = (int) $monthStart->format('t');

        // 1. Изтриваме старите смени за целевия месец
        $oldShifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.startDate >= :start AND s.endDate <= :end')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->getQuery()
            ->getResult();

        foreach ($oldShifts as $old) {
            $em->remove($old);
        }
        $em->flush();

        // 2. Индексираме легендите по код
        $legendsByCode = [];
        foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {
            $legendsByCode[mb_strtoupper(trim($l->getCode()))] = $l;
        }

        $userRepo = $em->getRepository(User::class);
        $created = 0;

        // 3. Групираме поредните дни и записваме смените (като при Excel импорта)
        foreach ($schedule as $row) {
            $user = $userRepo->find((int) ($row['userId'] ?? 0));
            if (!$user || empty($row['days']) || !is_array($row['days'])) {
                continue;
            }

            $daysByCode = [];
            foreach ($row['days'] as $dayNum => $rawVal) {
                $dayNum = (int) $dayNum;
                $val = trim((string) $rawVal);
                if ($val === '' || $dayNum < 1 || $dayNum > $daysInMonth) {
                    continue;
                }

                $codes = preg_split('/[\/,;\s+]+/u', $val, -1, PREG_SPLIT_NO_EMPTY);
                foreach (array_unique($codes) as $code) {
                    $codeUpper = mb_strtoupper(trim($code));
                    if (isset($legendsByCode[$codeUpper])) {
                        $daysByCode[$codeUpper][] = $dayNum;
                    }
                }
            }

            foreach ($daysByCode as $codeUpper => $dayNums) {
                sort($dayNums);
                $legend = $legendsByCode[$codeUpper];
                $rangeStart = $dayNums[0];
                $prevDay = $dayNums[0];

                $createShift = function (int $startD, int $endD) use ($em, $user, $legend, $year, $month, &$created) {
                    $startDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $startD));
                    $endDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $endD));
                    $daysCount = ($endD - $startD) + 1;
                    $hours = $daysCount * $legend->getDefaultDailyHours() * $legend->getMultiplier();

                    $location = match ($legend->getCode()) {
                        'КОМ' => 'Емона',
                        'О' => 'Отпуск',
                        'Б' => 'Болничен',
                        default => 'Плевен',
                    };

                    $shift = (new Shift())
                        ->setUser($user)
                        ->setLegendItem($legend)
                        ->setStartDate($startDate)
                        ->setEndDate($endDate)
                        ->setCalculatedHours($hours)
                        ->setLocation($location)
                        ->confirm();

                    $em->persist($shift);
                    $created++;
                };

                for ($i = 1; $i < count($dayNums); $i++) {
                    if ($dayNums[$i] === $prevDay + 1) {
                        $prevDay = $dayNums[$i];
                    } else {
                        $createShift($rangeStart, $prevDay);
                        $rangeStart = $dayNums[$i];
                        $prevDay = $dayNums[$i];
                    }
                }
                $createShift($rangeStart, $prevDay);
            }
        }

        $em->flush();

        return $this->json([
            'ok' => true,
            'message' => sprintf('Графикът за %02d.%d г. е записан успешно (%d записа).', $month, $year, $created),
            'redirectUrl' => $this->generateUrl('app_admin_report', ['month' => $targetMonth]),
        ]);
    }

    private function collectMonthlyWorkloadStats(EntityManagerInterface $em, HolidayService $holidayService): array
    {
        $year = (int) date('Y');
        $month = (int) date('m');
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        $normData = $holidayService->getMonthlyNorm($year, $month);
        $normHours = $normData['normHours'];

        $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC']);
        $regularUsers = array_values(array_filter(
            $allUsers,
            fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles(), true)
        ));

        $shifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.startDate <= :end AND s.endDate >= :start')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->getQuery()
            ->getResult();

        $tasks = $em->getRepository(Task::class)->findAll();

        $stats = [];
        foreach ($regularUsers as $u) {
            $stats[$u->getId()] = [
                'userId' => $u->getId(),
                'name' => trim(($u->getMilitaryRank() ?? '') . ' ' . $u->getFullName()),
                'position' => $u->getPosition() ?? '',
                'hours' => 0.0,
                'normHours' => $normHours,
                'diffFromNorm' => -$normHours,
                'tasksCompleted' => 0,
                'tasksActive' => 0,
            ];
        }

        foreach ($shifts as $s) {
            $uid = $s->getUser()->getId();
            if (isset($stats[$uid])) {
                $stats[$uid]['hours'] += $s->getCalculatedHours();
            }
        }

        foreach ($tasks as $t) {
            $uid = $t->getAssignedTo()?->getId();
            if ($uid && isset($stats[$uid])) {
                if ($t->isCompleted()) {
                    $stats[$uid]['tasksCompleted']++;
                } else {
                    $stats[$uid]['tasksActive']++;
                }
            }
        }

        foreach ($stats as &$row) {
            $row['diffFromNorm'] = $row['hours'] - $normHours;
        }
        unset($row);

        return [array_values($stats), sprintf('%02d.%d', $month, $year), $normHours];
    }

    #[Route('/tasks', name: 'app_admin_tasks', methods: ['GET'])]
    public function tasks(Request $request, EntityManagerInterface $em): Response
    {
        $filter = $request->query->get('filter', 'all');
        $qb = $em->getRepository(Task::class)->createQueryBuilder('t')
            ->orderBy('t.dueDate', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC');

        if ($filter === 'today') {
            $qb->andWhere('t.dueDate BETWEEN :s AND :e')
                ->setParameter('s', new \DateTimeImmutable('today 00:00:00'))
                ->setParameter('e', new \DateTimeImmutable('today 23:59:59'));
        } elseif ($filter === 'week') {
            $qb->andWhere('t.dueDate BETWEEN :s AND :e')
                ->setParameter('s', new \DateTimeImmutable('monday this week 00:00:00'))
                ->setParameter('e', new \DateTimeImmutable('sunday this week 23:59:59'));
        }

        $tasks = $qb->getQuery()->getResult();

        $bgMonths = [
            1 => 'Януари', 2 => 'Февруари', 3 => 'Март', 4 => 'Април',
            5 => 'Май', 6 => 'Юни', 7 => 'Юли', 8 => 'Август',
            9 => 'Септември', 10 => 'Октомври', 11 => 'Ноември', 12 => 'Декември',
        ];

        $groupedTasks = [];

        foreach ($tasks as $task) {
            $refDate = $task->getDueDate() ?? $task->getCreatedAt();
            $monthKey = $refDate->format('Y-m');
            $monthLabel = $bgMonths[(int) $refDate->format('n')] . ' ' . $refDate->format('Y') . ' г.';

            // Понеделник и неделя на съответната седмица
            $weekKey = $refDate->format('o-\WW');
            $weekNum = (int) $refDate->format('W');
            $weekStart = $refDate->modify('monday this week');
            $weekEnd = $weekStart->modify('+6 days');
            $weekLabel = sprintf(
                'Седмица %d (%s – %s)',
                $weekNum,
                $weekStart->format('d.m'),
                $weekEnd->format('d.m')
            );

            if (!isset($groupedTasks[$monthKey])) {
                $groupedTasks[$monthKey] = [
                    'label' => $monthLabel,
                    'total' => 0,
                    'active' => 0,
                    'weeks' => [],
                ];
            }

            if (!isset($groupedTasks[$monthKey]['weeks'][$weekKey])) {
                $groupedTasks[$monthKey]['weeks'][$weekKey] = [
                    'label' => $weekLabel,
                    'total' => 0,
                    'active' => 0,
                    'tasks' => [],
                ];
            }

            $groupedTasks[$monthKey]['total']++;
            $groupedTasks[$monthKey]['weeks'][$weekKey]['total']++;

            if (!$task->isCompleted()) {
                $groupedTasks[$monthKey]['active']++;
                $groupedTasks[$monthKey]['weeks'][$weekKey]['active']++;
            }

            $groupedTasks[$monthKey]['weeks'][$weekKey]['tasks'][] = $task;
        }

        return $this->render('admin/tasks.html.twig', [
            'groupedTasks' => $groupedTasks,
            'totalTasksCount' => count($tasks),
            'currentFilter' => $filter,
        ]);
    }

    #[Route('/report/user/{id}/update', name: 'app_admin_report_user_update', methods: ['POST'])]
    public function updateUserMonthSchedule(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $monthParam = (string) $request->request->get('month', date('Y-m'));
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
        } else {
            $year = (int) date('Y');
            $month = (int) date('m');
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        // 1. Трием старите смени само на този служител за избрания месец
        $oldShifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.user = :user AND s.startDate >= :start AND s.endDate <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->getQuery()
            ->getResult();

        foreach ($oldShifts as $old) {
            $em->remove($old);
        }
        $em->flush();

        // 2. Индексираме легендите по код
        $legendsByCode = [];
        foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {
            $legendsByCode[mb_strtoupper(trim($l->getCode()))] = $l;
        }

        // 3. Четем дните от формата (days[1] => 'РС', days[12] => 'КОМ/Д' и т.н.)
        $submittedDays = $request->request->all('days');
        $daysByCode = [];

        foreach ($submittedDays as $dayNum => $rawVal) {
            $dayNum = (int) $dayNum;
            $val = trim((string) $rawVal);
            if ($val === '' || $dayNum < 1 || $dayNum > 31) {
                continue;
            }

            $codes = preg_split('/[\/,;\s+]+/u', $val, -1, PREG_SPLIT_NO_EMPTY);
            foreach (array_unique($codes) as $code) {
                $codeUpper = mb_strtoupper(trim($code));
                if (isset($legendsByCode[$codeUpper])) {
                    $daysByCode[$codeUpper][] = $dayNum;
                }
            }
        }

        // 4. Групираме поредните дни и записваме новите смени
        foreach ($daysByCode as $codeUpper => $dayNums) {
            sort($dayNums);
            $legend = $legendsByCode[$codeUpper];

            $rangeStart = $dayNums[0];
            $prevDay = $dayNums[0];

            $createShift = function (int $startD, int $endD) use ($em, $user, $legend, $year, $month) {
                $startDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $startD));
                $endDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $endD));
                $daysCount = ($endD - $startD) + 1;
                $hours = $daysCount * $legend->getDefaultDailyHours() * $legend->getMultiplier();

                $shift = (new Shift())
                    ->setUser($user)
                    ->setLegendItem($legend)
                    ->setStartDate($startDate)
                    ->setEndDate($endDate)
                    ->setCalculatedHours($hours)
                    ->setLocation($legend->getCode() === 'КОМ' ? 'Емона' : 'Плевен')
                    ->confirm();

                $em->persist($shift);
            };

            for ($i = 1; $i < count($dayNums); $i++) {
                if ($dayNums[$i] === $prevDay + 1) {
                    $prevDay = $dayNums[$i];
                } else {
                    $createShift($rangeStart, $prevDay);
                    $rangeStart = $dayNums[$i];
                    $prevDay = $dayNums[$i];
                }
            }
            $createShift($rangeStart, $prevDay);
        }

        $em->flush();

        $this->addFlash('success', sprintf('Графикът на %s за %02d.%d г. е обновен.', $user->getFullName(), $month, $year));

        return $this->redirectToRoute('app_admin_report', [
            'month' => sprintf('%04d-%02d', $year, $month),
        ]);
    }

    #[Route('/report/import', name: 'app_admin_report_import', methods: ['POST'])]
    public function importReport(Request $request, EntityManagerInterface $em): Response
    {
        $file = $request->files->get('excelFile');
        if (!$file) {
            $this->addFlash('error', 'Моля, избери .xlsx файл за качване.');
            return $this->redirectToRoute('app_admin_report');
        }

        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();

        // 1. Определяме месец и година от A1 (напр. "Месечен табел за 11.2026 г.") или от формата
        $titleCell = (string) $sheet->getCell('A1')->getValue();
        if (preg_match('/(\d{2})\.(\d{4})/', $titleCell, $m)) {
            $month = (int) $m[1];
            $year = (int) $m[2];
        } else {
            $monthParam = (string) $request->request->get('month', date('Y-m'));
            if (preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $m)) {
                $year = (int) $m[1];
                $month = (int) $m[2];
            } else {
                $year = (int) date('Y');
                $month = (int) date('m');
            }
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        // 2. Ако е отметнато "Замени стария график", трием смените за този месец
        if ($request->request->getBoolean('replaceExisting')) {
            $oldShifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
                ->where('s.startDate >= :start AND s.endDate <= :end')
                ->setParameter('start', $monthStart)
                ->setParameter('end', $monthEnd)
                ->getQuery()
                ->getResult();

            foreach ($oldShifts as $old) {
                $em->remove($old);
            }
            $em->flush();
        }

        // 3. Индексираме потребителите и легендите за бързо търсене
        $usersByName = [];
        foreach ($em->getRepository(User::class)->findAll() as $u) {
            $fullWithRank = mb_strtolower(trim(($u->getMilitaryRank() ?? '') . ' ' . $u->getFullName()));
            $fullOnly = mb_strtolower(trim($u->getFullName()));
            $usersByName[$fullWithRank] = $u;
            $usersByName[$fullOnly] = $u;
        }

        $legendsByCode = [];
        foreach ($em->getRepository(LegendItem::class)->findAll() as $l) {
            $legendsByCode[mb_strtoupper(trim($l->getCode()))] = $l;
        }

        // 4. Намираме кои колони на ред 3 са дните от месеца (1..31)
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $dayColumns = [];
        for ($col = 2; $col <= $highestColIndex; $col++) {
            $val = trim((string) $sheet->getCell([$col, 3])->getValue());
            if (ctype_digit($val) && (int) $val >= 1 && (int) $val <= 31) {
                $dayColumns[$col] = (int) $val;
            }
        }

        $highestRow = $sheet->getHighestRow();
        $importedCount = 0;
        $skippedUsers = [];

        // 5. Обхождаме редовете със служители (от ред 4 надолу)
        for ($row = 4; $row <= $highestRow; $row++) {
            $rawName = trim((string) $sheet->getCell([1, $row])->getValue());
            if ($rawName === '') {
                continue;
            }

            $user = $usersByName[mb_strtolower($rawName)] ?? null;
            if (!$user) {
                $skippedUsers[] = $rawName;
                continue;
            }

            // Събираме по кодове кои дни има този служител (напр. 'КОМ' => [3,4,5,6,7,8,9])
            $daysByCode = [];
            foreach ($dayColumns as $col => $dayNum) {
                $cellVal = trim((string) $sheet->getCell([$col, $row])->getValue());
                if ($cellVal === '') {
                    continue;
                }

                // Поддържа единични (РС) и комбинирани (КОМ/Д) кодове
                $codes = preg_split('/[\/,;\s+]+/u', $cellVal, -1, PREG_SPLIT_NO_EMPTY);
                foreach (array_unique($codes) as $code) {
                    $codeUpper = mb_strtoupper(trim($code));
                    if (isset($legendsByCode[$codeUpper])) {
                        $daysByCode[$codeUpper][] = $dayNum;
                    }
                }
            }

            // Групираме поредните дни в отделни Shift записи (напр. 3..9 -> 1 смяна)
            foreach ($daysByCode as $codeUpper => $dayNums) {
                sort($dayNums);
                $legend = $legendsByCode[$codeUpper];

                $rangeStart = $dayNums[0];
                $prevDay = $dayNums[0];

                $createShift = function (int $startD, int $endD) use ($em, $user, $legend, $year, $month, &$importedCount) {
                    $startDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $startD));
                    $endDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $endD));
                    $daysCount = ($endD - $startD) + 1;
                    $hours = $daysCount * $legend->getDefaultDailyHours() * $legend->getMultiplier();

                    $shift = (new Shift())
                        ->setUser($user)
                        ->setLegendItem($legend)
                        ->setStartDate($startDate)
                        ->setEndDate($endDate)
                        ->setCalculatedHours($hours)
                        ->setLocation($legend->getCode() === 'КОМ' ? 'Емона' : 'Плевен')
                        ->confirm();

                    $em->persist($shift);
                    $importedCount++;
                };

                for ($i = 1; $i < count($dayNums); $i++) {
                    if ($dayNums[$i] === $prevDay + 1) {
                        $prevDay = $dayNums[$i];
                    } else {
                        $createShift($rangeStart, $prevDay);
                        $rangeStart = $dayNums[$i];
                        $prevDay = $dayNums[$i];
                    }
                }
                $createShift($rangeStart, $prevDay);
            }
        }

        $em->flush();

        $msg = sprintf('Импортирани са %d смени/дежурства за %02d.%d г.', $importedCount, $month, $year);
        if (!empty($skippedUsers)) {
            $msg .= ' Пропуснати (неразпознати имена): ' . implode(', ', array_unique($skippedUsers));
        }
        $this->addFlash('success', $msg);

        return $this->redirectToRoute('app_admin_report', [
            'month' => sprintf('%04d-%02d', $year, $month),
        ]);
    }

    #[Route('/report/export', name: 'app_admin_report_export', methods: ['GET'])]
    public function exportReport(Request $request, EntityManagerInterface $em, HolidayService $holidayService): StreamedResponse
    {
        $monthParam = (string) $request->query->get('month', date('Y-m'));
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthParam, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
        } else {
            $year = (int) $request->query->get('year', date('Y'));
            $month = (int) $monthParam;
        }

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $daysInMonth = (int) $monthStart->format('t');
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        $normData = $holidayService->getMonthlyNorm($year, $month);
        $holidays = $holidayService->getHolidaysForYear($year);

        $days = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateObj = $monthStart->setDate($year, $month, $d);
            $iso = $dateObj->format('Y-m-d');
            $dow = (int) $dateObj->format('N');
            $days[$d] = [
                'isWeekend' => $dow >= 6,
                'holidayName' => $holidays[$iso] ?? null,
            ];
        }

        $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC']);
        $regularUsers = array_values(array_filter(
            $allUsers,
            fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles(), true)
        ));

        $shifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.startDate <= :end AND s.endDate >= :start')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->getQuery()
            ->getResult();

        $reportRows = [];
        foreach ($regularUsers as $u) {
            $reportRows[$u->getId()] = [
                'name' => trim(($u->getMilitaryRank() ?? '') . ' ' . $u->getFullName()),
                'position' => $u->getPosition() ?? '',
                'days' => array_fill(1, $daysInMonth, []),
                'hours' => 0.0,
                'personalNorm' => $normData['normHours'],
            ];
        }

        foreach ($shifts as $shift) {
            $uid = $shift->getUser()->getId();
            if (!isset($reportRows[$uid])) {
                continue;
            }
            $reportRows[$uid]['hours'] += $shift->getCalculatedHours();

            $cur = max($shift->getStartDate(), $monthStart);
            $end = min($shift->getEndDate(), $monthEnd);
            while ($cur->format('Y-m-d') <= $end->format('Y-m-d')) {
                $dNum = (int) $cur->format('j');
                $reportRows[$uid]['days'][$dNum][] = $shift->getLegendItem()->getCode();
                $cur = $cur->modify('+1 day');
            }
        }

        foreach ($reportRows as &$row) {
            $excusedDays = 0;
            foreach ($row['days'] as $dayNum => $codes) {
                $isWorkday = !$days[$dayNum]['isWeekend'] && $days[$dayNum]['holidayName'] === null;
                if ($isWorkday && (in_array('О', $codes, true) || in_array('Б', $codes, true))) {
                    $excusedDays++;
                }
            }
            $row['personalNorm'] = max(0, $normData['normHours'] - ($excusedDays * 8));
        }
        unset($row);

        // Създаване на Excel файла
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(sprintf('Табел %02d.%d', $month, $year));

        // Заглавие
        $sheet->setCellValue('A1', sprintf('Месечен табел за %02d.%d г. (Месечна норма: %d ч.)', $month, $year, $normData['normHours']));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        // Хедър ред (ред 3)
        $sheet->setCellValue('A3', 'Служител');
        $colIndex = 2;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($colLetter . '3', $d);
            if ($days[$d]['isWeekend'] || $days[$d]['holidayName'] !== null) {
                $sheet->getStyle($colLetter . '3')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFE5E5');
            }
            $sheet->getColumnDimension($colLetter)->setWidth(5);
            $colIndex++;
        }

        $hoursCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++);
        $normCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex++);
        $diffCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);

        $sheet->setCellValue($hoursCol . '3', 'Отраб. ч.');
        $sheet->setCellValue($normCol . '3', 'Лична норма');
        $sheet->setCellValue($diffCol . '3', 'Разлика');

        $headerRange = 'A3:' . $diffCol . '3';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Данни по служители (от ред 4)
        $rowNum = 4;
        foreach ($reportRows as $row) {
            $sheet->setCellValue('A' . $rowNum, $row['name']);
            $colIndex = 2;
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                $val = implode('/', array_unique($row['days'][$d]));
                $sheet->setCellValue($colLetter . $rowNum, $val);

                if ($days[$d]['isWeekend'] || $days[$d]['holidayName'] !== null) {
                    $sheet->getStyle($colLetter . $rowNum)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFF8F9FA');
                }
                $colIndex++;
            }

            $diff = $row['hours'] - $row['personalNorm']; // + над норма, - недостиг
            $sheet->setCellValue($hoursCol . $rowNum, $row['hours']);
            $sheet->setCellValue($normCol . $rowNum, $row['personalNorm']);
            $sheet->setCellValue($diffCol . $rowNum, $diff);

            $rowNum++;
        }

        // Рамки и подравняване
        $lastRow = max(4, $rowNum - 1);
        $tableRange = 'A3:' . $diffCol . $lastRow;
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('B4:' . $diffCol . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension($hoursCol)->setWidth(12);
        $sheet->getColumnDimension($normCol)->setWidth(13);
        $sheet->getColumnDimension($diffCol)->setWidth(11);

        $filename = sprintf('tabel_%02d_%d.xlsx', $month, $year);
        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    #[Route('/leaves/{id}/approve', name: 'app_admin_leave_approve', methods: ['POST'])]
    public function approveLeave(LeaveRequest $leave, EntityManagerInterface $em, HolidayService $holidayService): Response
    {
        if ($leave->getStatus() === 'pending') {
            $legend = $em->getRepository(LegendItem::class)->findOneBy(['code' => $leave->getType()]);
            if ($legend) {
                $shift = (new Shift())
                    ->setUser($leave->getUser())
                    ->setLegendItem($legend)
                    ->setStartDate($leave->getStartDate())
                    ->setEndDate($leave->getEndDate())
                    ->setCalculatedHours(0)
                    ->setLocation($leave->getType() === 'Б' ? 'Болничен' : 'Отпуск')
                    ->confirm();

                $em->persist($shift);
            }

            // Ако е отпуск ('О'), приспадаме работните дни първо от избраната/най-старата година
            if ($leave->getType() === 'О') {
                $daysToDeduct = $holidayService->countWorkingDays($leave->getStartDate(), $leave->getEndDate());

                if ($leave->getLeaveYear() !== null) {
                    $chosenBal = $em->getRepository(LeaveBalance::class)->findOneBy([
                        'user' => $leave->getUser(),
                        'year' => $leave->getLeaveYear(),
                    ]);
                    if ($chosenBal && $chosenBal->getRemainingDays() > 0) {
                        $take = min($chosenBal->getRemainingDays(), $daysToDeduct);
                        $chosenBal->setUsedDays($chosenBal->getUsedDays() + $take);
                        $daysToDeduct -= $take;
                    }
                }

                if ($daysToDeduct > 0) {
                    $balances = $em->getRepository(LeaveBalance::class)->findBy(
                        ['user' => $leave->getUser()],
                        ['year' => 'ASC']
                    );
                    foreach ($balances as $bal) {
                        if ($daysToDeduct <= 0) {
                            break;
                        }
                        $avail = $bal->getRemainingDays();
                        if ($avail > 0) {
                            $take = min($avail, $daysToDeduct);
                            $bal->setUsedDays($bal->getUsedDays() + $take);
                            $daysToDeduct -= $take;
                        }
                    }
                }
            }

            $leave->setStatus('approved');
            $em->flush();
            $this->addFlash('success', 'Заявката е одобрена и добавена в графика.');
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/leaves', name: 'app_admin_leaves', methods: ['GET'])]
    public function leaves(EntityManagerInterface $em): Response
    {
        $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC']);
        $regularUsers = array_values(array_filter(
            $allUsers,
            fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles(), true)
        ));

        $balances = $em->getRepository(LeaveBalance::class)->findBy([], ['year' => 'ASC']);
        $byUser = [];
        foreach ($balances as $b) {
            $byUser[$b->getUser()->getId()][] = $b;
        }

        return $this->render('admin/leaves.html.twig', [
            'users' => $regularUsers,
            'balancesByUser' => $byUser,
            'currentYear' => (int) date('Y'),
        ]);
    }

    #[Route('/leaves/set-balance', name: 'app_admin_leave_set_balance', methods: ['POST'])]
    public function setLeaveBalance(Request $request, EntityManagerInterface $em): Response
    {
        $userId = (string) $request->request->get('userId');
        $year = (int) $request->request->get('year', date('Y'));
        $totalDays = max(0, (int) $request->request->get('totalDays', 30));
        $usedDays = max(0, (int) $request->request->get('usedDays', 0));

        if ($userId === 'all') {
            $allUsers = $em->getRepository(User::class)->findAll();
            foreach ($allUsers as $u) {
                if (in_array('ROLE_ADMIN', $u->getRoles(), true)) {
                    continue;
                }
                $bal = $em->getRepository(LeaveBalance::class)->findOneBy(['user' => $u, 'year' => $year]);
                if (!$bal) {
                    $bal = (new LeaveBalance())->setUser($u)->setYear($year)->setTotalDays($totalDays)->setUsedDays(0);
                    $em->persist($bal);
                }
            }
            $em->flush();
            $this->addFlash('success', sprintf('Заредени са по %d дни отпуск за %d г. на всички без запис.', $totalDays, $year));
        } else {
            $user = $em->getRepository(User::class)->find((int) $userId);
            if ($user) {
                $bal = $em->getRepository(LeaveBalance::class)->findOneBy(['user' => $user, 'year' => $year]);
                if (!$bal) {
                    $bal = (new LeaveBalance())->setUser($user)->setYear($year);
                    $em->persist($bal);
                }
                $bal->setTotalDays($totalDays)->setUsedDays($usedDays);
                $em->flush();
                $this->addFlash('success', sprintf('Обновен отпуск за %s (%d г.): %d / %d дни.', $user->getFullName(), $year, $bal->getRemainingDays(), $totalDays));
            }
        }

        return $this->redirectToRoute('app_admin_leaves');
    }

    #[Route('/leaves/{id}/reject', name: 'app_admin_leave_reject', methods: ['POST'])]
    public function rejectLeave(LeaveRequest $leave, EntityManagerInterface $em): Response
    {
        $leave->setStatus('rejected');
        $em->flush();
        $this->addFlash('success', 'Заявката е отказана.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/shifts/create', name: 'app_admin_shift_create', methods: ['POST'])]
    public function createShift(Request $request, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find((int) $request->request->get('userId'));
        $legend = $em->getRepository(LegendItem::class)->find((int) $request->request->get('legendId'));
        $startRaw = (string) $request->request->get('startDate');
        $endRaw = (string) $request->request->get('endDate');
        $location = trim((string) $request->request->get('location'));

        if ($user && $legend && $startRaw !== '' && $endRaw !== '') {
            $start = new \DateTimeImmutable($startRaw);
            $end = new \DateTimeImmutable($endRaw);
            if ($end < $start) {
                $end = $start;
            }

            $customHours = $request->request->get('customHours');

            if ($customHours !== null && $customHours !== '') {
                $hours = (float) $customHours;
            } else {
                $days = (int) $start->diff($end)->days + 1;
                $hours = $days * $legend->getDefaultDailyHours() * $legend->getMultiplier();
            }

            $shift = (new Shift())
                ->setUser($user)
                ->setLegendItem($legend)
                ->setStartDate($start)
                ->setEndDate($end)
                ->setCalculatedHours($hours)
                ->setLocation($location !== '' ? $location : $legend->getName());

            $em->persist($shift);
            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'Записана смяна [%s] за %s (%s – %s, %s ч.).',
                    $legend->getCode(),
                    $user->getFullName(),
                    $start->format('d.m'),
                    $end->format('d.m'),
                    $hours
                )
            );
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/legends', name: 'app_admin_legends', methods: ['GET'])]
    public function legends(EntityManagerInterface $em): Response
    {
        $legends = $em->getRepository(LegendItem::class)->findBy([], ['code' => 'ASC']);

        return $this->render('admin/legends.html.twig', [
            'legends' => $legends,
        ]);
    }

    #[Route('/report', name: 'app_admin_report', methods: ['GET'])]
    public function report(Request $request, EntityManagerInterface $em, HolidayService $holidayService): Response
    {
        $month = (string) $request->query->get('month', (new \DateTimeImmutable())->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = (new \DateTimeImmutable())->format('Y-m');
        }

        $monthStart = new \DateTimeImmutable($month . '-01 00:00:00');
        $monthEnd = $monthStart->modify('last day of this month 23:59:59');
        $daysInMonth = (int) $monthStart->format('t');

        $year = (int) $monthStart->format('Y');
        $monthNum = (int) $monthStart->format('m');
        $normData = $holidayService->getMonthlyNorm($year, $monthNum);

        // Списък с дните от месеца (1..30/31) и дали са уикенд или официален празник
        $days = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $monthStart->setDate($year, $monthNum, $d);
            $dow = (int) $date->format('N');
            $days[$d] = [
                'num' => $d,
                'isWeekend' => $dow >= 6,
                'holidayName' => $normData['holidays'][$d] ?? null,
            ];
        }

        $allUsers = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC']);
        $regularUsers = array_values(array_filter(
            $allUsers,
            fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles(), true)
        ));

        $shifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.startDate <= :end AND s.endDate >= :start')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->orderBy('s.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        $tasks = $em->getRepository(Task::class)->createQueryBuilder('t')
            ->where('t.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->getQuery()
            ->getResult();

        $reportRows = [];
        $companyTotalHours = 0.0;
        $companyTotalTasks = 0;
        $companyCompletedTasks = 0;

        foreach ($regularUsers as $u) {
            $reportRows[$u->getId()] = [
                'user' => $u,
                'days' => array_fill(1, $daysInMonth, []),
                'hours' => 0.0,
                'tasksTotal' => 0,
                'tasksCompleted' => 0,
            ];
        }

        foreach ($shifts as $shift) {
            $uid = $shift->getUser()->getId();
            if (!isset($reportRows[$uid])) {
                continue;
            }

            $reportRows[$uid]['hours'] += $shift->getCalculatedHours();
            $companyTotalHours += $shift->getCalculatedHours();

            $cur = $shift->getStartDate() < $monthStart ? $monthStart : $shift->getStartDate();
            $end = $shift->getEndDate() > $monthEnd ? $monthEnd : $shift->getEndDate();

            while ($cur->format('Y-m-d') <= $end->format('Y-m-d')) {
                $dayNum = (int) $cur->format('j');
                $reportRows[$uid]['days'][$dayNum][] = [
                    'code' => $shift->getLegendItem()->getCode(),
                    'name' => $shift->getLegendItem()->getName(),
                    'color' => $shift->getLegendItem()->getColorHex(),
                    'location' => $shift->getLocation() ?? '',
                    'hours' => $shift->getCalculatedHours(),
                ];
                $cur = $cur->modify('+1 day');
            }
        }

        foreach ($tasks as $task) {
            $uid = $task->getAssignedTo()->getId();
            if (!isset($reportRows[$uid])) {
                continue;
            }
            $reportRows[$uid]['tasksTotal']++;
            $companyTotalTasks++;
            if ($task->isCompleted()) {
                $reportRows[$uid]['tasksCompleted']++;
                $companyCompletedTasks++;
            }
        }

        $legends = $em->getRepository(LegendItem::class)->findBy([], ['code' => 'ASC']);
        foreach ($reportRows as &$row) {
            $excusedDays = 0;
            foreach ($row['days'] as $dayNum => $cellShifts) {
                $isWorkday = !$days[$dayNum]['isWeekend'] && $days[$dayNum]['holidayName'] === null;
                if (!$isWorkday) {
                    continue;
                }
                foreach ($cellShifts as $cs) {
                    if (in_array($cs['code'], ['О', 'Б'], true)) {
                        $excusedDays++;
                        break;
                    }
                }
            }
            $row['personalNorm'] = max(0, $normData['normHours'] - ($excusedDays * 8));
        }
        unset($row);

        return $this->render('admin/report.html.twig', [
            'rows' => array_values($reportRows),
            'days' => $days,
            'legends' => $legends,
            'companyTotalHours' => $companyTotalHours,
            'companyTotalTasks' => $companyTotalTasks,
            'companyCompletedTasks' => $companyCompletedTasks,
            'selectedMonth' => $month,
            'workingDays' => $normData['workingDays'],
            'normHours' => $normData['normHours'],
            'year' => $year,
            'month' => $month,
        ]);
    }

    #[Route('/legends/create', name: 'app_admin_legend_create', methods: ['POST'])]
    public function createLegend(Request $request, EntityManagerInterface $em): Response
    {
        $code = mb_strtoupper(trim((string) $request->request->get('code')));
        $name = trim((string) $request->request->get('name'));
        $hours = (float) $request->request->get('defaultDailyHours', 0);
        $multiplier = (float) $request->request->get('multiplier', 1.0);
        $color = trim((string) $request->request->get('colorHex', '#0d6efd'));

        if ($code !== '' && $name !== '') {
            $existing = $em->getRepository(LegendItem::class)->findOneBy(['code' => $code]);
            if ($existing) {
                $this->addFlash('danger', sprintf('Кодът [%s] вече съществува.', $code));
            } else {
                $legend = (new LegendItem())
                    ->setCode($code)
                    ->setName($name)
                    ->setDefaultDailyHours($hours)
                    ->setMultiplier($multiplier > 0 ? $multiplier : 1.0)
                    ->setColorHex($color);

                $em->persist($legend);
                $em->flush();

                $this->addFlash('success', sprintf('Добавен е тип [%s] %s (%s ч.).', $code, $name, $hours));
            }
        }

        return $this->redirectToRoute('app_admin_legends');
    }

    #[Route('/legends/{id}/delete', name: 'app_admin_legend_delete', methods: ['POST'])]
    public function deleteLegend(LegendItem $legend, EntityManagerInterface $em): Response
    {
        $used = $em->getRepository(Shift::class)->count(['legendItem' => $legend]);

        if ($used > 0) {
            $this->addFlash('danger', 'Не може да изтриеш този тип, защото вече има записани смени с него.');
        } else {
            $em->remove($legend);
            $em->flush();
            $this->addFlash('success', 'Типът смяна беше изтрит.');
        }

        return $this->redirectToRoute('app_admin_legends');
    }

    #[Route('/shifts/{id}/delete', name: 'app_admin_shift_delete', methods: ['POST'])]
    public function deleteShift(Shift $shift, EntityManagerInterface $em): Response
    {
        $em->remove($shift);
        $em->flush();

        $this->addFlash('success', 'Смяната беше изтрита.');
        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/tasks/create', name: 'app_admin_task_create', methods: ['POST'])]
    public function createTask(Request $request, EntityManagerInterface $em): Response
    {
        $user = $em->getRepository(User::class)->find((int) $request->request->get('assignedTo'));
        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description'));
        $dueDateRaw = (string) $request->request->get('dueDate');

        if ($user && !in_array('ROLE_ADMIN', $user->getRoles(), true) && $title !== '') {
            $task = (new Task())
                ->setAssignedTo($user)
                ->setTitle($title)
                ->setDescription($description !== '' ? $description : null);

            if ($dueDateRaw !== '') {
                $task->setDueDate(new \DateTimeImmutable($dueDateRaw));
            }

            $em->persist($task);
            $em->flush();

            $dueText = $task->getDueDate()
                ? $task->getDueDate()->format('d.m.Y в H:i ч.')
                : 'без краен срок';

            $this->addFlash(
                'success',
                sprintf(
                    'Възложена задача „%s“ на %s %s (възложена в %s ч. | за %s).',
                    $task->getTitle(),
                    $user->getMilitaryRank() ?? '',
                    $user->getFullName(),
                    $task->getCreatedAt()->format('H:i'),
                    $dueText
                )
            );
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/users', name: 'app_admin_users', methods: ['GET'])]
    public function users(EntityManagerInterface $em): Response
    {
        $users = $em->getRepository(User::class)->findBy([], ['lastName' => 'ASC']);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/users/{id}/toggle-role', name: 'app_admin_user_toggle_role', methods: ['POST'])]
    public function toggleRole(User $user, EntityManagerInterface $em): Response
    {
        if ($user !== $this->getUser()) {
            $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
            $user->setRoles($isAdmin ? ['ROLE_USER'] : ['ROLE_ADMIN']);
            $em->flush();
        }

        return $this->redirectToRoute('app_admin_users');
    }

    #[Route('/users/{id}/toggle-approve', name: 'app_admin_user_toggle_approve', methods: ['POST'])]
    public function toggleApprove(User $user, EntityManagerInterface $em): Response
    {
        $user->setIsApproved(!$user->isApproved());
        $em->flush();

        return $this->redirectToRoute('app_admin_users');
    }
    #[Route('/users/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, EntityManagerInterface $em): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'Не можеш да изтриеш собствения си профил.');
            return $this->redirectToRoute('app_admin_users');
        }

        // 1. Изчистваме свързаните записи, за да няма Foreign Key грешка
        $em->createQuery('DELETE FROM App\Entity\Shift s WHERE s.user = :u')
            ->setParameter('u', $user)->execute();

        $em->createQuery('DELETE FROM App\Entity\Task t WHERE t.assignedTo = :u')
            ->setParameter('u', $user)->execute();

        $em->createQuery('DELETE FROM App\Entity\LeaveRequest lr WHERE lr.user = :u')
            ->setParameter('u', $user)->execute();

        $em->createQuery('DELETE FROM App\Entity\LeaveBalance lb WHERE lb.user = :u')
            ->setParameter('u', $user)->execute();

        // 2. Изтриваме самия потребител
        $name = $user->getFullName();
        $em->remove($user);
        $em->flush();

        $this->addFlash('success', sprintf('Потребителят %s и всички негови записи бяха изтрити.', $name));

        return $this->redirectToRoute('app_admin_users');
    }
}
