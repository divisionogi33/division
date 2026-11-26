<?php

namespace App\Controller;

use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Entity\LegendItem;
use App\Entity\Shift;
use App\Entity\Task;
use App\Entity\User;
use App\Service\HolidayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CalendarController extends AbstractController
{
    #[Route('/', name: 'app_calendar')]
    public function index(Request $request, EntityManagerInterface $em, HolidayService $holidayService): Response    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $allShifts = $em->getRepository(Shift::class)->findBy([], ['startDate' => 'ASC']);
        $legends = $em->getRepository(LegendItem::class)->findAll();
        $myLeaves = $em->getRepository(LeaveRequest::class)->findBy(
            ['user' => $currentUser],
            ['createdAt' => 'DESC'],
            5
        );
        // Филтър за задачи: today | week | all
        $filter = $request->query->get('filter', 'all');
        $qb = $em->getRepository(Task::class)->createQueryBuilder('t')
            ->orderBy('t.isCompleted', 'ASC')
            ->addOrderBy('t.dueDate', 'ASC');

        if (!$isAdmin) {
            $qb->andWhere('t.assignedTo = :u')->setParameter('u', $currentUser);
        }
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

        // Смени за потвърждение от текущия потребител
        $myShifts = array_values(array_filter(
            $allShifts,
            fn(Shift $s) => $s->getUser()->getId() === $currentUser->getId()
        ));
        $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');
        $monthEnd = new \DateTimeImmutable('last day of this month 23:59:59');
        $normData = $holidayService->getMonthlyNorm(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('m')
        );

        $monthShifts = $em->getRepository(Shift::class)->createQueryBuilder('s')
            ->where('s.startDate <= :end AND s.endDate >= :start')
            ->setParameter('start', $monthStart)
            ->setParameter('end', $monthEnd)
            ->orderBy('s.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        $batteryTotalHours = 0.0;
        $individualHours = [];

        foreach ($monthShifts as $shift) {
            $hours = $shift->getCalculatedHours();
            $batteryTotalHours += $hours;

            $user = $shift->getUser();
            if (!$isAdmin && $user->getId() !== $currentUser->getId()) {
                continue;
            }

            $uid = $user->getId();
            if (!isset($individualHours[$uid])) {
                $individualHours[$uid] = [
                    'name' => trim(($user->getMilitaryRank() ?? '') . ' ' . $user->getFullName()),
                    'position' => $user->getPosition() ?? '',
                    'hours' => 0.0,
                ];
            }
            $individualHours[$uid]['hours'] += $hours;
        }
        $batteryTotalHours = 0.0;
        $individualHours = [];

        foreach ($allShifts as $shift) {
            $hours = $shift->getCalculatedHours();
            $batteryTotalHours += $hours;

            $user = $shift->getUser();
            if (!$isAdmin && $user->getId() !== $currentUser->getId()) {
                continue;
            }

            $uid = $user->getId();
            if (!isset($individualHours[$uid])) {
                $individualHours[$uid] = [
                    'name' => trim(($user->getMilitaryRank() ?? '') . ' ' . $user->getFullName()),
                    'position' => $user->getPosition() ?? '',
                    'hours' => 0.0,
                ];
            }
            $individualHours[$uid]['hours'] += $hours;
        }
        $currentYear = (int) date('Y');
        $hasCurrentYear = $em->getRepository(LeaveBalance::class)->findOneBy([
            'user' => $currentUser,
            'year' => $currentYear,
        ]);

        if (!$hasCurrentYear && !$isAdmin) {
            $newBal = (new LeaveBalance())
                ->setUser($currentUser)
                ->setYear($currentYear)
                ->setTotalDays(30)
                ->setUsedDays(0);
            $em->persist($newBal);
            $em->flush();
        }
        $myLeaveBalances = $em->getRepository(LeaveBalance::class)->findBy(
            ['user' => $currentUser],
            ['year' => 'ASC']
        );

        if (!$isAdmin && empty($individualHours)) {
            $individualHours[$currentUser->getId()] = [
                'name' => trim(($currentUser->getMilitaryRank() ?? '') . ' ' . $currentUser->getFullName()),
                'position' => $currentUser->getPosition() ?? '',
                'hours' => 0.0,
            ];
        }

        return $this->render('calendar/index.html.twig', [
            'batteryTotalHours' => $batteryTotalHours,
            'individualHours' => $individualHours,
            'tasks' => $tasks,
            'myShifts' => $myShifts,
            'legends' => $legends,
            'hasData' => count($allShifts) > 0,
            'currentFilter' => $filter,
            'myLeaves' => $myLeaves,
            'myLeaveBalances' => $myLeaveBalances,
            'currentMonth' => $monthStart->format('m.Y'),
            'normHours' => $normData['normHours'],
        ]);
    }
    #[Route('/shifts/{id}/confirm', name: 'app_shift_confirm', methods: ['POST'])]
    public function confirmShift(Shift $shift, EntityManagerInterface $em): Response
    {
        if ($shift->getUser() === $this->getUser()) {
            $shift->confirm();
            $em->flush();
        }

        return $this->redirectToRoute('app_calendar');
    }
    #[Route('/leave/request', name: 'app_leave_request', methods: ['POST'])]
    public function requestLeave(Request $request, EntityManagerInterface $em): Response
    {
        $type = (string) $request->request->get('type');
        $startRaw = (string) $request->request->get('startDate');
        $endRaw = (string) $request->request->get('endDate');
        $reason = trim((string) $request->request->get('reason'));
        $leaveYearRaw = $request->request->get('leaveYear');
        $leaveYear = ($type === 'О' && $leaveYearRaw !== null && $leaveYearRaw !== '')
            ? (int) $leaveYearRaw
            : null;

        if (!in_array($type, ['О', 'Б'], true) || $startRaw === '' || $endRaw === '') {
            $this->addFlash('error', 'Моля, попълнете всички задължителни полета.');
            return $this->redirectToRoute('app_calendar');
        }

        $today = new \DateTimeImmutable('today');
        $start = (new \DateTimeImmutable($startRaw))->setTime(0, 0, 0);
        $end = (new \DateTimeImmutable($endRaw))->setTime(0, 0, 0);

        if ($end < $start) {
            $end = $start;
        }

        // 1. Отпуск: не може със задна дата
        if ($type === 'О' && $start < $today) {
            $this->addFlash('error', 'Заявката за отпуск не може да бъде със задна дата.');
            return $this->redirectToRoute('app_calendar');
        }

        // 2. Болничен: допуска се до 2 работни дни назад (съгласно нормативния срок)
        if ($type === 'Б') {
            $minSickDate = $today;
            $workingDaysBack = 0;
            while ($workingDaysBack < 2) {
                $minSickDate = $minSickDate->modify('-1 day');
                if ((int) $minSickDate->format('N') < 6) { // Понеделник–Петък
                    $workingDaysBack++;
                }
            }

            if ($start < $minSickDate) {
                $this->addFlash(
                    'error',
                    sprintf(
                        'Болничен може да се подава най-много до 2 работни дни назад (най-ранна дата: %s).',
                        $minSickDate->format('d.m.Y')
                    )
                );
                return $this->redirectToRoute('app_calendar');
            }
        }

        $leave = (new LeaveRequest())
            ->setUser($this->getUser())
            ->setType($type)
            ->setLeaveYear($leaveYear)
            ->setStartDate($start)
            ->setEndDate($end)
            ->setReason($reason !== '' ? $reason : null);

        $em->persist($leave);
        $em->flush();

        $this->addFlash('success', 'Заявката беше изпратена за одобрение.');

        return $this->redirectToRoute('app_calendar');
    }

    #[Route('/api/events', name: 'api_calendar_events', methods: ['GET'])]
    public function events(EntityManagerInterface $em): JsonResponse
    {
        $shiftRepo = $em->getRepository(Shift::class);

        if ($this->isGranted('ROLE_ADMIN')) {
            $shifts = $shiftRepo->findAll();
        } else {
            $shifts = $shiftRepo->findBy(['user' => $this->getUser()]);
        }

        $events = [];

        foreach ($shifts as $shift) {
            $user = $shift->getUser();
            $legend = $shift->getLegendItem();

            $events[] = [
                'id' => $shift->getId(),
                'title' => sprintf(
                    '[%s] %s — %s (%s ч.)',
                    $legend->getCode(),
                    $user->getFullName(),
                    $shift->getLocation() ?? $legend->getName(),
                    $shift->getCalculatedHours()
                ),
                'start' => $shift->getStartDate()->format('Y-m-d'),
                // FullCalendar третира крайната дата като exclusive, затова +1 ден
                'end' => $shift->getEndDate()->modify('+1 day')->format('Y-m-d'),
                'color' => $legend->getColorHex(),
                'extendedProps' => [
                    'location' => $shift->getLocation() ?? $legend->getName(),
                    'hours' => $shift->getCalculatedHours(),
                ],
            ];
        }

        return $this->json($events);
    }

    #[Route('/api/tasks/{id}/toggle', name: 'api_task_toggle', methods: ['POST'])]
    public function toggleTask(Task $task, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $currentUser = $this->getUser();

        if ($task->getAssignedTo() !== $currentUser && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Нямаш права за тази задача.'], Response::HTTP_FORBIDDEN);
        }

        if ($task->isCompleted()) {
            return $this->json(['error' => 'Задачата вече е приключена и не може да се променя.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $note = trim((string) ($data['note'] ?? ''));

        $task->toggleCompleted();
        $task->setCompletionNote($note !== '' ? $note : null);

        $em->flush();

        return $this->json([
            'id' => $task->getId(),
            'isCompleted' => $task->isCompleted(),
            'completedAt' => $task->getCompletedAt()?->format('d.m.Y H:i'),
            'completionNote' => $task->getCompletionNote(),
        ]);
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(EntityManagerInterface $em): JsonResponse
    {
        // Endpoint за keep-alive бота (UptimeRobot / Render + MySQL)
        $em->getConnection()->executeQuery('SELECT 1');
        return $this->json(['status' => 'ok']);
    }

    #[Route('/seed-demo', name: 'app_seed_demo')]
    public function seedDemo(EntityManagerInterface $em): Response
    {
        // Ако вече има данни, не дублираме
        if (count($em->getRepository(LegendItem::class)->findAll()) > 0) {
            return $this->redirectToRoute('app_calendar');
        }

        // 1. Легенда
        $emona = (new LegendItem())
            ->setCode('ЕМ')
            ->setName('Командировка Емона')
            ->setDefaultDailyHours(24)
            ->setMultiplier(1.0)
            ->setColorHex('#dc3545');

        $workShift = (new LegendItem())
            ->setCode('РС')
            ->setName('Работна смяна')
            ->setDefaultDailyHours(8)
            ->setMultiplier(1.0)
            ->setColorHex('#0d6efd');

        $em->persist($emona);
        $em->persist($workShift);

        // 2. Тестови потребители
        $user1 = (new User())
            ->setEmail('ivanov@division.local')
            ->setFirstName('Иван')
            ->setLastName('Иванов')
            ->setMilitaryRank('ст. серж.')
            ->setPosition('Оператор РЛС')
            ->setIsApproved(true);

        $user2 = (new User())
            ->setEmail('petrov@division.local')
            ->setFirstName('Петър')
            ->setLastName('Петров')
            ->setMilitaryRank('кап.')
            ->setPosition('Началник станция')
            ->setIsApproved(true);

        $em->persist($user1);
        $em->persist($user2);

        // 3. Тестови смени за текущия месец
        $today = new \DateTimeImmutable('today');

        $shift1 = (new Shift())
            ->setUser($user1)
            ->setLegendItem($emona)
            ->setStartDate($today)
            ->setEndDate($today->modify('+4 days'))
            ->setCalculatedHours(120)
            ->setLocation('Емона');

        $shift2 = (new Shift())
            ->setUser($user2)
            ->setLegendItem($workShift)
            ->setStartDate($today->modify('+1 day'))
            ->setEndDate($today->modify('+3 days'))
            ->setCalculatedHours(24)
            ->setLocation('Плевен');

        $em->persist($shift1);
        $em->persist($shift2);

        // 4. Тестови задачи
        $task1 = (new Task())
            ->setAssignedTo($user1)
            ->setShift($shift1)
            ->setTitle('Профилактика и проверка на захранването')
            ->setDueDate($today->modify('+1 day'));

        $task2 = (new Task())
            ->setAssignedTo($user2)
            ->setShift($shift2)
            ->setTitle('Отчет на часовете за седмицата')
            ->setDueDate($today->modify('+2 days'));

        $em->persist($task1);
        $em->persist($task2);

        $em->flush();

        return $this->redirectToRoute('app_calendar');
    }

}
